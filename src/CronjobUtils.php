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

namespace Schwendinger\Webtrees\Module\Cronjob;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Webtrees;
use PDOException;
use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;

use function basename;
use function get_current_user;
use function glob;
use function in_array;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function str_replace;

/**
 * Module-level helpers: schema migration, job registry access,
 * script discovery and the generated trigger-installation blocks.
 */
final class CronjobUtils {

    public const MODULE_NAME = '_cronjob_';

    public const SCHEMA_NAME = 'SCHEMA_VERSION';

    private const MODULE_SCRIPT_PATTERN = '#^modules_v4/[a-z0-9_\-]+/cli/[a-z0-9_\-]+\.php$#';

    /**
     * Apply Migrate# class files (zero based) until target_version - 1.
     *
     * Same approach as linkenhancer's LinkEnhancerUtils::updateSchema():
     * DDL runs outside the request transaction (MySQL implicit commits).
     */
    public static function updateSchema(AbstractModule $module, int $target_version): void {
        try {
            $current_version = (int) $module->getPreference(self::SCHEMA_NAME);
        } catch (PDOException) {
            // During initial installation the site tables may not be usable yet.
            $current_version = 0;
        }

        $connection = DB::schema()->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        try {
            while ($current_version < $target_version) {
                $class     = '\Schwendinger\Webtrees\Module\Cronjob\Schema\Migration' . $current_version;
                $migration = new $class();
                $migration->upgrade();
                $current_version++;

                // The module row may not exist yet during first installation.
                if (DB::table('module')->where('module_name', '=', $module->name())->exists()) {
                    $module->setPreference(self::SCHEMA_NAME, (string) $current_version);
                }
            }
        } finally {
            // Re-open a transaction for webtrees' middleware to commit.
            $connection->beginTransaction();
        }
    }

    /**
     * All jobs plus the data of their most recent run.
     *
     * @return list<array<string, mixed>>
     */
    public static function listJobs(): array {
        $jobs = DB::table('cj_job')->orderBy('name')->get()->all();

        $latest = [];
        $runs   = DB::table('cj_run')
            ->get(['id', 'job_id', 'started_at', 'finished_at', 'status', 'exit_code', 'duration_ms'])
            ->all();
        foreach ($runs as $run) {
            $job_id = (int) $run->job_id;
            if (!isset($latest[$job_id]) || strcmp((string) $run->started_at, (string) $latest[$job_id]->started_at) > 0) {
                $latest[$job_id] = $run;
            }
        }

        foreach ($jobs as $job) {
            $job->last_run = $latest[(int) $job->id] ?? null;
        }

        return $jobs;
    }

    /**
     * @return object|null
     */
    public static function findJob(int $id): ?object {
        return DB::table('cj_job')->where('id', '=', $id)->first();
    }

    /**
     * Insert or update a job row. $data keys: name, title, cron,
     * command_type, command, args, timeout_sec, enabled, created_at.
     *
     * With $id > 0 the existing row is updated by id - renaming a job
     * (changing its slug) then works instead of creating a duplicate.
     * next_run_at is (re)computed from $now.
     */
    public static function saveJob(array $data, string $now, int $id = 0): void {
        $values = [
            'title'        => $data['title'],
            'trigger_type' => 'time',
            'cron'         => $data['cron'],
            'command_type' => $data['command_type'],
            'command'      => $data['command'],
            'args'         => $data['args'],
            'enabled'      => $data['enabled'] ? 1 : 0,
            'timeout_sec'  => $data['timeout_sec'],
            'next_run_at'  => Services\ScheduleService::nextRun($data['cron'], $now),
            'updated_at'   => $now,
        ];

        if ($id > 0) {
            DB::table('cj_job')->where('id', '=', $id)->update($values + [
                'name' => $data['name'],
            ]);
        } else {
            DB::table('cj_job')->insert($values + [
                'name'       => $data['name'],
                'created_at' => $data['created_at'] ?? $now,
            ]);
        }
    }

    /**
     * Delete a job (run history is removed by the FK cascade).
     */
    public static function deleteJob(int $id): void {
        DB::table('cj_job')->where('id', '=', $id)->delete();
    }

    /**
     * Discover all module CLI scripts that may be used as jobs
     * (modules_v4/&lt;module&gt;/cli/&lt;script&gt;.php, template files with a '_' prefix excluded).
     *
     * @return list<string> relative paths, e.g. 'modules_v4/linkenhancer/cli/build-link-index.php'
     */
    public static function jobScriptCandidates(): array {
        $root = Webtrees::MODULES_DIR;

        $files = glob($root . '*/cli/*.php') ?: [];

        $candidates = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (str_starts_with($base, '_')) {
                continue;
            }
            $candidates[] = str_replace(
                [Webtrees::ROOT_DIR, '\\'],
                ['', '/'],
                $file
            );
        }
        sort($candidates);

        return $candidates;
    }

    /**
     * Determine the command type from the command value
     * (the form uses a single field; the runner validates both).
     *
     * @return 'module'|'core'|''
     */
    public static function detectCommandType(string $command): string {
        if (preg_match(self::MODULE_SCRIPT_PATTERN, $command) === 1) {
            return 'module';
        }
        if (in_array($command, JobRunner::ALLOWED_CORE_COMMANDS, true)) {
            return 'core';
        }
        return '';
    }

    /**
     * Copy-paste blocks for the „Trigger-Installation" section:
     * one classic cron line plus a systemd service/timer pair,
     * all derived from this installation (paths, PHP binary).
     *
     * @return array<string, string>
     */
    public static function triggerInstallBlocks(): array {
        $root = realpath(rtrim(str_replace('\\', '/', Webtrees::ROOT_DIR), '/'));
        $php  = str_replace('\\', '/', WatchService::phpBinary());
        $tick = 'modules_v4/cronjob/cli/tick.php';
        $log  = $root . '/data/cronjob-tick.log';

        return [
            'cron_line'   => '* * * * * cd ' . $root . ' && ' . $php . ' ' . $tick . ' cron:tick >> ' . $log . ' 2>&1',
            'service_unit' => implode("\n", [
                '[Unit]',
                'Description=webtrees cronjob module tick (runs due maintenance jobs)',
                '',
                '[Service]',
                'Type=oneshot',
                'User=' . get_current_user(),
                'WorkingDirectory=' . $root,
                'ExecStart=' . $php . ' ./' . $tick . ' cron:tick',
            ]),
            'timer_unit' => implode("\n", [
                '[Unit]',
                'Description=Run the webtrees cronjob tick every minute',
                '',
                '[Timer]',
                'OnBootSec=1min',
                'OnCalendar=*:*:00',
                'Persistent=true',
                '',
                '[Install]',
                'WantedBy=timers.target',
            ]),
            'install_commands' => implode("\n", [
                '# as root, once:',
                'sudo cp wt-cronjob-tick.service wt-cronjob-tick.timer /etc/systemd/system/',
                'sudo systemctl daemon-reload',
                'sudo systemctl enable --now wt-cronjob-tick.timer',
            ]),
        ];
    }
}
