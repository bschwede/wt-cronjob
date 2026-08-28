<?php
/*
 * webtrees - cronjob (custom module)
 *
 * Copyright (C) 2026 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2026 webtrees development team.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\Cronjob\Services;

use function array_merge;
use function count;
use function feof;
use function fread;
use function getenv;
use function in_array;
use function is_file;
use function is_resource;
use function microtime;
use function preg_match;
use function preg_split;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function strlen;
use function str_contains;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function substr;
use function trim;
use function usleep;

/**
 * Validates and executes job commands as isolated child processes.
 *
 * Security model (no shell, ever):
 * - command_type 'module': path must match modules_v4/<module>/cli/<script>.php,
 *   must exist, and realpath must stay inside a modules_v4/<module>/cli/ directory
 * - command_type 'core':   command must be in ALLOWED_CORE_COMMANDS;
 *   'site-setting' only with the read-only '--list' argument
 * - every argument token must be a plain option or value (no shell
 *   metacharacters, no spaces inside a token)
 */
final class JobRunner {

    /** Core CLI commands a job may invoke (read-only / export only). */
    public const ALLOWED_CORE_COMMANDS = [
        'tree-export',
        'tree-list',
        'user-list',
        'site-setting',
    ];

    private const MAX_OUTPUT_BYTES = 65536;
    private const MAX_ARGS         = 10;

    private const MODULE_COMMAND_PATTERN = '#^modules_v4/[a-z0-9_\-]+/cli/[a-z0-9_\-]+\.php$#';
    private const OPTION_TOKEN           = '/^--[a-zA-Z][a-zA-Z0-9_-]*$/';
    private const OPTION_EQ_TOKEN        = '/^--[a-zA-Z][a-zA-Z0-9_-]*=[A-Za-z0-9_.\\/-]+$/';
    private const VALUE_TOKEN            = '/^[A-Za-z0-9_.\\/-]+$/';

    /**
     * Build the executable argv for a job.
     *
     * @param array<string, mixed> $job  row from cj_job
     *
     * @return array{argv: list<string>, error: string}
     */
    public static function buildArgv(array $job, string $root_dir): array {
        $command_type = (string) ($job['command_type'] ?? '');
        $command      = trim((string) ($job['command'] ?? ''));
        $args         = trim((string) ($job['args'] ?? ''));

        if ($command === '') {
            return ['argv' => [], 'error' => 'command is empty'];
        }

        if ($command_type === 'module') {
            if (preg_match(self::MODULE_COMMAND_PATTERN, $command) !== 1) {
                return ['argv' => [], 'error' => 'command is not a modules_v4/<module>/cli/<script>.php path'];
            }
            $abs = $root_dir . '/' . $command;
            if (!is_file($abs)) {
                return ['argv' => [], 'error' => 'script does not exist: ' . $command];
            }
            $real        = realpath($abs);
            $modules_dir = realpath($root_dir . '/modules_v4');
            if ($real === false || $modules_dir === false
                || !str_starts_with($real, $modules_dir . DIRECTORY_SEPARATOR)
                || !str_contains($real, DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR)
            ) {
                return ['argv' => [], 'error' => 'script path escapes modules_v4/*/cli/'];
            }
            $argv = [PHP_BINARY, $real];
        } elseif ($command_type === 'core') {
            if (!in_array($command, self::ALLOWED_CORE_COMMANDS, true)) {
                return ['argv' => [], 'error' => 'core command not allowlisted: ' . $command];
            }
            $argv = [PHP_BINARY, $root_dir . '/index.php', $command];
        } else {
            return ['argv' => [], 'error' => 'unknown command type: ' . $command_type];
        }

        $tokens = [];
        if ($args !== '') {
            $tokens = preg_split('/\s+/', $args) ?: [];
            if (count($tokens) > self::MAX_ARGS) {
                return ['argv' => [], 'error' => 'too many arguments (max ' . self::MAX_ARGS . ')'];
            }
            foreach ($tokens as $token) {
                $valid = preg_match(self::OPTION_TOKEN, $token) === 1
                    || preg_match(self::OPTION_EQ_TOKEN, $token) === 1
                    || preg_match(self::VALUE_TOKEN, $token) === 1;
                if (!$valid) {
                    return ['argv' => [], 'error' => 'invalid argument token: ' . $token];
                }
            }
        }

        // site-setting must stay read-only - also when no args are given.
        if ($command_type === 'core' && $command === 'site-setting' && !in_array('--list', $tokens, true)) {
            return ['argv' => [], 'error' => 'site-setting requires the read-only --list argument'];
        }

        $argv = array_merge($argv, $tokens);

        return ['argv' => $argv, 'error' => ''];
    }

    /**
     * Working directory for the child process.
     *
     * Module scripts run from the webtrees root (their documented
     * invocation). Core CLI commands run from data/ - the core writes
     * relative to the CWD (e.g. tree-export: <tree>.ged), and data/ is
     * the designated place for generated files, not the web root.
     *
     * @param array<string, mixed> $job
     */
    public static function cwdFor(array $job, string $root_dir): string {
        return ((string) ($job['command_type'] ?? '') === 'core')
            ? $root_dir . 'data/'
            : $root_dir;
    }

    /**
     * Execute argv synchronously with a hard timeout.
     *
     * The child runs with the given working directory (the webtrees root,
     * so module scripts can rely on their documented invocation). Output
     * (stdout+stderr) is captured, capped to the last MAX_OUTPUT_BYTES.
     *
     * $extra_env (event runs only, see TickCommand) is merged OVER the tick
     * process' environment - proc_open with an env array REPLACES it, so the
     * parent's variables are carried along explicitly. With an empty array
     * the child inherits the environment untouched (all non-event runs).
     *
     * The exit code comes from the last proc_get_status() snapshot that
     * observed the exit - that call reaps the child, so proc_close() would
     * report -1 (ECHILD); proc_close's value is used only when it does the
     * reaping itself (>= 0).
     *
     * @param list<string> $argv
     * @param array<string, string> $extra_env
     *
     * @return array{exit: int, output: string, timed_out: bool}
     */
    public static function run(array $argv, int $timeout, string $cwd, array $extra_env = []): array {
        $env   = $extra_env === [] ? null : array_merge(getenv(), $extra_env);
        $pipes = [];
        $proc  = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

        if (!is_resource($proc)) {
            return ['exit' => 1, 'output' => 'proc_open() failed', 'timed_out' => false];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline  = microtime(true) + max(1, $timeout);
        $output    = '';
        $running   = true;
        $exit_code = -1;

        while ($running) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $read    = [$pipes[1], $pipes[2]];
            $write   = null;
            $except  = null;
            $wait_us = (int) (min(0.5, $deadline - microtime(true)) * 1000000);

            $changed = stream_select($read, $write, $except, 0, $wait_us);
            if ($changed > 0) {
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                            $output = substr($output, -self::MAX_OUTPUT_BYTES);
                        }
                    }
                }
            }

            $status  = proc_get_status($proc);
            $running = $status['running'];
            if (!$running) {
                // proc_get_status has reaped the child - proc_close() would
                // then report -1 (ECHILD), so the real exit code must come
                // from the status snapshot.
                $exit_code = (int) $status['exitcode'];
                self::drainPipes($pipes);
            }
        }

        $timed_out = $running;
        if ($timed_out) {
            proc_terminate($proc, 9);
        }

        // proc_close reaps the child only if the status polling did not
        // already - its return value is authoritative when >= 0.
        $close_code = proc_close($proc);
        if ($close_code >= 0) {
            $exit_code = $close_code;
        }

        return [
            'exit'      => $timed_out ? -9 : $exit_code,
            'output'    => $output,
            'timed_out' => $timed_out,
        ];
    }

    /**
     * Read the remaining pipe content after the process stopped
     * (bounded - non-blocking pipes can report EOF late).
     *
     * @param array<int, resource> $pipes
     */
    private static function drainPipes(array $pipes): void {
        foreach ($pipes as $pipe) {
            for ($i = 0; $i < 50; $i++) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        break;
                    }
                    usleep(2000);
                }
            }
        }
    }
}
