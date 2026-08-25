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

use Fisharebest\Webtrees\Webtrees;
use Throwable;

use function array_map;
use function date;
use function dirname;
use function explode;
use function fclose;
use function filesize;
use function filemtime;
use function file_put_contents;
use function fopen;
use function flock;
use function function_exists;
use function fwrite;
use function getmypid;
use function implode;
use function in_array;
use function ini_get;
use function is_file;
use function is_resource;
use function max;
use function microtime;
use function min;
use function proc_close;
use function proc_open;
use function rename;
use function sleep;
use function sprintf;
use function time;
use function touch;
use function unlink;

/**
 * The optional resident "watch" daemon and its page-load watchdog.
 *
 * The daemon (cli/watch.php) runs the tick from a long-lived process instead
 * of an OS cron/systemd timer - for hosts without cron. A small watchdog in
 * the module's boot() respawns it if it dies. It is strictly opt-in: nothing
 * runs unless an admin clicks "Start".
 *
 * State (all in data/):
 *   cronjob-watch.enabled    opt-in marker (Start creates it, Stop removes it)
 *   cronjob-watch.lock       liveness - the daemon holds it for its lifetime
 *   cronjob-watch.heartbeat  mtime = last loop iteration
 *   cronjob-watch-spawn      mtime = last spawn attempt (respawn cooldown)
 *   cronjob-watch.log        one supervisor line per tick (never job output)
 */
final class WatchService {

    public const SPAWN_COOLDOWN = 300; // seconds between respawn attempts
    public const TICK_TIMEOUT   = 300; // seconds a single tick may take

    private const FEATURE_FILE   = 'cronjob-watch.enabled';
    private const LOCK_FILE      = 'cronjob-watch.lock';
    private const HEARTBEAT_FILE = 'cronjob-watch.heartbeat';
    private const SPAWN_FILE     = 'cronjob-watch-spawn';
    private const LOG_FILE       = 'cronjob-watch.log';
    private const LOG_MAX_BYTES  = 1048576;

    /**
     * Absolute path of a state file in the data/ directory.
     */
    private static function path(string $name): string {
        return Webtrees::DATA_DIR . $name;
    }

    /**
     * Absolute path to one of the module's CLI scripts.
     */
    private static function cliScript(string $name): string {
        return dirname(__DIR__, 2) . '/cli/' . $name;
    }

    /**
     * Is the watch feature enabled (opt-in marker present)?
     */
    public static function featureEnabled(): bool {
        return is_file(self::path(self::FEATURE_FILE));
    }

    /**
     * Enable the watch feature (create the opt-in marker).
     */
    public static function enable(): void {
        @touch(self::path(self::FEATURE_FILE));
    }

    /**
     * Disable the watch feature. A running daemon exits within one loop
     * iteration and the watchdog will not respawn it.
     */
    public static function disable(): void {
        @unlink(self::path(self::FEATURE_FILE));
    }

    /**
     * Is a watch daemon currently alive? It holds the lock for its whole
     * lifetime; the OS releases the lock automatically when the process dies.
     */
    public static function daemonAlive(): bool {
        $lock = @fopen(self::path(self::LOCK_FILE), 'c');
        if ($lock === false) {
            return false;
        }
        $acquired = flock($lock, LOCK_EX | LOCK_NB);
        fclose($lock);

        return !$acquired;
    }

    /**
     * Has the respawn cooldown (counted from the last spawn attempt) elapsed?
     */
    public static function spawnCooldownElapsed(): bool {
        $file = self::path(self::SPAWN_FILE);

        return !is_file($file) || (time() - (int) filemtime($file)) >= self::SPAWN_COOLDOWN;
    }

    /**
     * Spawn the daemon, detached (it is re-parented to init and survives the
     * request). Returns true when a daemon is running afterwards - either
     * because it was just started or because one was already running.
     */
    public static function spawn(): bool {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return false;
        }
        if (self::daemonAlive()) {
            return true;
        }

        @touch(self::path(self::SPAWN_FILE));
        $proc = @proc_open(
            [PHP_BINARY, self::cliScript('watch.php')],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            Webtrees::ROOT_DIR
        );
        if (!is_resource($proc)) {
            return false;
        }
        proc_close($proc);

        return true;
    }

    /**
     * Page-load watchdog: respawn a dead daemon, but only when the admin has
     * enabled the watch. Safe to call on every request - when the feature is
     * off it is a single file_exists(). Never throws and never blocks.
     */
    public static function maybeSpawn(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            if (!self::featureEnabled()
                || CliBootstrap::siteIsOffline()
                || self::daemonAlive()
                || !self::spawnCooldownElapsed()
            ) {
                return;
            }
            self::spawn();
        } catch (Throwable) {
            // The watchdog must never break an HTTP request.
        }
    }

    /**
     * Status for the admin UI.
     *
     * @return array{enabled: bool, running: bool, last_tick: int}
     */
    public static function status(): array {
        $heartbeat = self::path(self::HEARTBEAT_FILE);

        return [
            'enabled'   => self::featureEnabled(),
            'running'   => self::daemonAlive(),
            'last_tick' => is_file($heartbeat) ? (int) filemtime($heartbeat) : 0,
        ];
    }

    /**
     * The daemon's main loop. Holds the liveness lock, then ticks once a
     * minute until it is disabled, re-deployed, or the host goes away.
     */
    public static function loop(): void {
        $lock = @fopen(self::path(self::LOCK_FILE), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            fwrite(STDOUT, 'another watch daemon is already running' . PHP_EOL);

            return;
        }
        @fwrite($lock, (string) getmypid());

        $fingerprint = self::installFingerprint();
        while (self::featureEnabled() && self::installFingerprint() === $fingerprint) {
            if (!CliBootstrap::siteIsOffline()) {
                self::runTick();
            }
            @touch(self::path(self::HEARTBEAT_FILE));
            self::sleepInterruptible(60);
        }

        fwrite(STDOUT, 'watch daemon exiting' . PHP_EOL);
        fclose($lock);
    }

    /**
     * Run one tick as an isolated child process (the tick does its own guard,
     * offline check, boot and tick-lock). Only a supervisor line is logged -
     * never the job output, which may contain personal data.
     */
    private static function runTick(): void {
        $started = microtime(true);
        $result  = JobRunner::run([PHP_BINARY, self::cliScript('tick.php')], self::TICK_TIMEOUT, Webtrees::ROOT_DIR);
        $ms      = (int) round((microtime(true) - $started) * 1000);

        self::appendLog(sprintf('%s tick exit=%d ms=%d', date('c'), $result['exit'], $ms));
    }

    /**
     * Fingerprint of the code the daemon runs. A change means the module was
     * (re)deployed, so the daemon exits and is respawned with the new code.
     */
    private static function installFingerprint(): string {
        $base  = dirname(__DIR__, 2);
        $files = [
            $base . '/module.php',
            $base . '/cli/watch.php',
            $base . '/cli/tick.php',
            $base . '/src/Services/WatchService.php',
            $base . '/src/Services/CliBootstrap.php',
            $base . '/src/Services/JobRunner.php',
        ];

        $parts = [];
        foreach ($files as $file) {
            $parts[] = (string) @filemtime($file);
        }

        return implode(':', $parts);
    }

    /**
     * Sleep up to $seconds, waking early if the feature gets disabled so the
     * loop stops promptly (stop latency well under one minute).
     */
    private static function sleepInterruptible(int $seconds): void {
        $end = time() + $seconds;
        while (time() < $end) {
            if (!self::featureEnabled()) {
                return;
            }
            sleep(max(1, min(5, $end - time())));
        }
    }

    /**
     * Append one line to the supervisor log, rotating it when it grows large
     * so it stays bounded.
     */
    private static function appendLog(string $line): void {
        $file = self::path(self::LOG_FILE);
        if (is_file($file) && filesize($file) > self::LOG_MAX_BYTES) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
