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
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Webtrees;
use PDOException;
use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;
use Throwable;

use function array_is_list;
use function array_values;
use function basename;
use function get_current_user;
use function glob;
use function in_array;
use function is_array;
use function preg_match;
use function rtrim;
use function str_contains;
use function strlen;
use function str_starts_with;
use function str_replace;
use function strpos;
use function substr;

/**
 * Module-level helpers: schema migration, job registry access,
 * script discovery and the generated trigger-installation blocks.
 */
final class CronjobUtils {

    public const MODULE_NAME = '_cronjob_';

    public const SCHEMA_NAME = 'SCHEMA_VERSION';

    private const MODULE_SCRIPT_PATTERN = '#^modules_v4/[a-z0-9_\-]+/cli/[a-z0-9_\-]+\.php$#';

    /**
     * Apply Migration# class files (zero based) until target_version - 1.
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
            ->get(['id', 'job_id', 'started_at', 'finished_at', 'status', 'exit_code', 'duration_ms', 'trigger', 'trigger_detail'])
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
     * Insert or update a job row. $data keys: name, title, triggers
     * (normalized list, see ScheduleService::normalizeTriggers()),
     * command_type, command, args, timeout_sec, enabled, created_at.
     *
     * With $id > 0 the existing row is updated by id - renaming a job
     * (changing its slug) then works instead of creating a duplicate.
     * next_run_at is (re)computed from $now as the minimum over the time
     * triggers (§13); the trigger rows are replaced.
     *
     * @param array<string, mixed> $data
     */
    public static function saveJob(array $data, string $now, int $id = 0): void {
        $triggers = (array) ($data['triggers'] ?? []);

        $values = [
            'title'        => $data['title'],
            'command_type' => $data['command_type'],
            'command'      => $data['command'],
            'args'         => $data['args'],
            'enabled'      => $data['enabled'] ? 1 : 0,
            'notify'       => (!empty($data['notify'])) ? 1 : 0,
            'timeout_sec'  => $data['timeout_sec'],
            'next_run_at'  => Services\ScheduleService::nextRunMin($triggers, $now),
            'updated_at'   => $now,
        ];

        if ($id > 0) {
            DB::table('cj_job')->where('id', '=', $id)->update($values + [
                'name' => $data['name'],
            ]);
        } else {
            $id = (int) DB::table('cj_job')->insertGetId($values + [
                'name'       => $data['name'],
                'created_at' => $data['created_at'] ?? $now,
            ]);
        }
        Services\ScheduleService::replaceJobTriggers($id, $triggers);
    }

    /**
     * Delete a job (run history is removed by the FK cascade).
     */
    public static function deleteJob(int $id): void {
        DB::table('cj_job')->where('id', '=', $id)->delete();
    }

    /**
     * Next free slug for a duplicated job: <base>-copy, <base>-copy2, …
     * The base is truncated to 59 chars so the suffix still fits into the
     * 64-char cj_job.name column.
     *
     * @param (callable(string): bool)|null $exists returns true if $slug is taken (default: cj_job DB check)
     */
    public static function uniqueCopySlug(string $base, ?callable $exists = null): string {
        $exists ??= static fn (string $slug): bool => DB::table('cj_job')->where('name', '=', $slug)->exists();

        $base = substr($base, 0, 59);
        $n    = 1;

        do {
            $slug = $n === 1 ? $base . '-copy' : $base . '-copy' . (string) $n;
            $n++;
        } while ($exists($slug) && $n < 1000);

        return $slug;
    }

    /**
     * Name base for a duplicated job: module-offered jobs are keyed
     * <module>:<name>, but the colon cannot be entered in the form (slug
     * pattern), so the prefix is stripped for the copy. Anything else is
     * returned unchanged.
     */
    public static function copySlugBase(string $name): string {
        if (preg_match('/^[a-z0-9_\-]+:/', $name) === 1) {
            return substr($name, strpos($name, ':') + 1);
        }

        return $name;
    }

    /**
     * Whether a job name (slug) is valid.
     *
     * A plain slug is always valid. A module-offered key (`<module>:<slug>`) is
     * accepted only when $allow_module_key is set - callers pass it true solely to
     * preserve an existing offered name on update, never to create or retarget one.
     * The whole name (colon included) must fit the 64-char cj_job.name column, so
     * the two parts are not each allowed up to 64 characters.
     */
    public static function isValidJobName(string $name, bool $allow_module_key = false): bool {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $name) === 1) {
            return true;
        }

        return $allow_module_key
            && strlen($name) <= 64
            && preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}:[a-z0-9][a-z0-9_\-]{0,63}$/', $name) === 1;
    }

    /**
     * Whether an event name is valid under the event naming scheme (analogous
     * to job names): a plain slug (custom / webhook events) or a namespaced
     * `<domain>:<slug>` (route events `_route:*`, built-in pseudo-events
     * `cronjob:*`, module-announced events `<module>:*`). Unlike job names,
     * the domain part may start with an underscore (reserved domains like
     * `_route`). The whole name must fit the 64-char cj_event.event_name
     * column.
     */
    public static function isValidEventName(string $name): bool {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $name) === 1) {
            return true;
        }

        return strlen($name) <= 64
            && preg_match('/^[a-z0-9_][a-z0-9_\-]{0,63}:[a-z0-9][a-z0-9_\-]{0,63}$/', $name) === 1;
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
     * Load a <module>/cron-jobs.php manifest in an isolated scope.
     *
     * The file returns either a plain list of job specs (simple form) or an
     * associative array with optional 'jobs', 'events' and 'commands' lists
     * (event announcements, see ScheduleService::discoverExternalEvents(),
     * and command announcements, see
     * ScheduleService::discoverExternalCommands()). Returns the normalized
     * ['jobs' => …, 'events' => …, 'commands' => …] shape, or three empty
     * lists on any error - a broken or misbehaving manifest must never break
     * the tick. Same containment strategy as core's ModuleService::load()
     * (include in a try/catch); a manifest that calls exit() cannot be
     * contained (same limitation as module.php).
     *
     * @return array{jobs: list<array<string, mixed>>, events: list<array<string, mixed>>, commands: list<array<string, mixed>>}
     */
    public static function loadManifestFile(string $path): array {
        if (!is_file($path)) {
            return ['jobs' => [], 'events' => [], 'commands' => []];
        }

        $loader = static function (string $p): array {
            $result = include $p;
            if (!is_array($result)) {
                return ['jobs' => [], 'events' => [], 'commands' => []];
            }
            if (array_is_list($result)) {
                $jobs = [];
                foreach ($result as $spec) {
                    if (is_array($spec)) {
                        $jobs[] = $spec;
                    }
                }

                return ['jobs' => $jobs, 'events' => [], 'commands' => []];
            }

            $jobs     = $result['jobs'] ?? [];
            $events   = $result['events'] ?? [];
            $commands = $result['commands'] ?? [];

            return [
                'jobs'     => is_array($jobs) ? array_values($jobs) : [],
                'events'   => is_array($events) ? array_values($events) : [],
                'commands' => is_array($commands) ? array_values($commands) : [],
            ];
        };

        try {
            return $loader($path);
        } catch (Throwable) {
            return ['jobs' => [], 'events' => [], 'commands' => []];
        }
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

    /**
     * Render-time translation of a (admin-renamable) job title.
     *
     * I18N::translate() applies sprintf() to the lookup result; a title
     * containing a bare '%' (user data) would be an invalid conversion
     * specification (PHP warning + false → TypeError in I18N::translate():
     * string). Admin-renamed titles are user data anyway, so we skip
     * translation for those instead of risking a view crash.
     */
    public static function translateJobTitle(string $title): string {
        if (str_contains($title, '%')) {
            return $title;
        }

        return I18N::translate($title);
    }
}
