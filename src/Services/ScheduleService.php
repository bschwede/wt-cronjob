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

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Webtrees;
use InvalidArgumentException;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Throwable;

use function array_flip;
use function array_intersect_key;
use function array_merge;
use function class_exists;
use function count;
use function date;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function max;
use function method_exists;
use function preg_match;
use function preg_split;
use function str_pad;
use function strpos;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const STR_PAD_LEFT;

/**
 * Schedule state machine for cj_job / cj_run.
 *
 * All cron evaluation and all stored timestamps use UTC - the same basis
 * the webtrees core uses (Webtrees::bootstrap() sets the default timezone
 * to UTC), so tick and admin UI always agree.
 *
 * A job stores its next due time (next_run_at). After every run (or edit)
 * the next time is recomputed from "now" - therefore downtime or clock
 * jumps cause at most ONE catch-up run per job, never a burst.
 */
final class ScheduleService {

    public const TIMEZONE = 'UTC';

    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_ERROR   = 'error';
    public const STATUS_TIMEOUT = 'timeout';

    public const RUN_RETENTION_PER_JOB = 25;
    public const RUN_RETENTION_DAYS    = 30;
    /** A 'running' row older than this can no longer have a live process. */
    public const STUCK_AFTER_SECONDS   = 3660;

    /** Trigger types for cj_job.trigger_type. */
    public const TRIGGER_TIME  = 'time';
    public const TRIGGER_EVENT = 'event';

    // Job-spec timeout bounds; single source of truth (CronjobModule's form
    // limits reference these). Kept here so validateJobSpec() stays free of a
    // CronjobModule dependency (standalone-testable without the webtrees core).
    public const TIMEOUT_MIN = 30;
    public const TIMEOUT_MAX = 3600;
    public const TIMEOUT_STD = 300;

    /**
     * Is the bundled cron-expression library present (vendor fetched)?
     */
    public static function hasCronLibrary(): bool {
        return class_exists('Cron\\CronExpression');
    }

    public static function cronLibraryMissingMessage(): string {
        return 'Cron library missing: fetch dragonmantank/cron-expression into '
            . 'modules_v4/cronjob/vendor/dragonmantank/cron-expression/ '
            . '(see README.md, section "Bundled dependency").';
    }

    /**
     * Parse a cron expression (throws on invalid input).
     *
     * The library (v3.6.0) throws InvalidArgumentException for invalid
     * expressions - normalized to DomainException here so all callers
     * share one catch type. The timezone is NOT a factory parameter in
     * this version; it is passed explicitly to getNextRunDate() below.
     *
     * @throws DomainException|RuntimeException
     */
    public static function parse(string $cron): CronExpression {
        if (!self::hasCronLibrary()) {
            throw new RuntimeException(self::cronLibraryMissingMessage());
        }
        try {
            return CronExpression::factory($cron);
        } catch (InvalidArgumentException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws DomainException|RuntimeException
     */
    public static function validateCron(string $cron): bool {
        self::parse($cron);

        return true;
    }

    /**
     * Next run after $from (UTC 'Y-m-d H:i:s'), formatted the same way.
     *
     * @throws DomainException|RuntimeException
     */
    public static function nextRun(string $cron, string $from): string {
        $dt = self::parse($cron)
            ->getNextRunDate(new DateTimeImmutable($from, new DateTimeZone(self::TIMEZONE)), 0, false, self::TIMEZONE);

        return $dt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    /**
     * The next $count runs after $from (for the form preview).
     *
     * @return list<string>
     *
     * @throws DomainException|RuntimeException
     */
    public static function upcomingRuns(string $cron, int $count, string $from): array {
        $runs   = [];
        $cursor = new DateTimeImmutable($from, new DateTimeZone(self::TIMEZONE));
        $cron   = self::parse($cron);

        for ($i = 0; $i < max(1, $count); $i++) {
            $cursor = $cron->getNextRunDate($cursor, 0, false, self::TIMEZONE);
            $runs[] = $cursor->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
        }

        return $runs;
    }

    /**
     * A human-readable description of a common 5-field cron expression (UI add-on).
     *
     * Handles the patterns that maintenance jobs actually use; anything it cannot
     * express confidently is returned unchanged (the raw cron), so the UI can show
     * the human form only when it differs. All user-facing phrases go through
     * I18N::translate(); it is standalone-testable with a trivial I18N shim that
     * returns sprintf(original, ...args) - the same result webtrees yields when no
     * translation is loaded. Always shown next to the exact cron string.
     */
    public static function humanizeCron(string $cron): string {
        $cron = trim($cron);
        if ($cron === '') {
            return '';
        }

        // Standard macros (the same set the cron library accepts).
        $macros = [
            '@minutely'  => I18N::translate('every minute'),
            '@minute'    => I18N::translate('every minute'),
            '@hourly'    => I18N::translate('hourly'),
            '@daily'     => I18N::translate('daily at 00:00'),
            '@midnight'  => I18N::translate('daily at 00:00'),
            '@weekly'    => I18N::translate('weekly on Sunday at 00:00'),
            '@monthly'   => I18N::translate('monthly on day 1 at 00:00'),
            '@yearly'    => I18N::translate('yearly on January 1 at 00:00'),
            '@annually'  => I18N::translate('yearly on January 1 at 00:00'),
        ];
        if (isset($macros[$cron])) {
            return $macros[$cron];
        }

        $fields = preg_split('/\s+/', $cron);
        if (count($fields) !== 5) {
            return $cron; // seconds field, 7-field, etc. - not handled
        }
        [$min, $hour, $dom, $mon, $dow] = $fields;

        // "every minute"
        if ($min === '*' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            return I18N::plural('every minute', 'every %1$d minutes', 1, 1);
        }
        // "every N minutes" (plural form chosen by the locale's CLDR rules)
        if (preg_match('/^\*\/(\d+)$/', $min, $m) === 1 && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $n = (int) $m[1];

            return I18N::plural('every minute', 'every %1$d minutes', $n, $n);
        }
        // "hourly" (minute 0, every hour) or "every hour at :MM"
        if (self::isCronInt($min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $n = (int) $min;

            return $n === 0 ? I18N::translate('hourly') : I18N::translate('every hour at minute %1$d', $n);
        }
        // "daily at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
            return I18N::translate('daily at %1$s', self::time2((int) $hour, (int) $min));
        }
        // "on <weekday> at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && $dom === '*' && $mon === '*' && self::isDow($dow)) {
            return I18N::translate('on %1$s at %2$s', self::dowNames($dow), self::time2((int) $hour, (int) $min));
        }
        // "on day D of each month at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && self::isCronInt($dom) && $mon === '*' && $dow === '*') {
            return I18N::translate('on day %1$d of each month at %2$s', (int) $dom, self::time2((int) $hour, (int) $min));
        }

        return $cron; // no confident human form - show the raw expression
    }

    /**
     * A field is a single 0-59 / 0-23 style integer.
     */
    private static function isCronInt(string $field): bool {
        return preg_match('/^\d{1,2}$/', $field) === 1;
    }

    /**
     * A day-of-week field that is a number or a list/range of numbers.
     */
    private static function isDow(string $dow): bool {
        if (in_array($dow, ['*'], true)) {
            return false;
        }

        return preg_match('/^(\d{1,2})(-?\d{1,2})?(,(\d{1,2})(-?\d{1,2})?)*$/', $dow) === 1;
    }

    /**
     * HH:MM, zero-padded.
     */
    private static function time2(int $hour, int $minute): string {
        return str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Human names for a day-of-week field: "1-5" -> "Monday to Friday",
     * "0,6" -> "Sunday and Saturday", "1" -> "Monday" (all translatable).
     */
    private static function dowNames(string $dow): string {
        $names = [
            I18N::translate('Sunday'),
            I18N::translate('Monday'),
            I18N::translate('Tuesday'),
            I18N::translate('Wednesday'),
            I18N::translate('Thursday'),
            I18N::translate('Friday'),
            I18N::translate('Saturday'),
        ];
        $name  = fn (int $d): string => $names[(((int) $d) % 7 + 7) % 7];

        $parts = [];
        foreach (explode(',', $dow) as $chunk) {
            if (strpos($chunk, '-') !== false) {
                [$a, $b] = explode('-', $chunk, 2);
                $parts[] = I18N::translate('%1$s to %2$s', $name((int) $a), $name((int) $b));
            } else {
                $parts[] = $name((int) $chunk);
            }
        }

        if (count($parts) === 2) {
            return I18N::translate('%1$s and %2$s', $parts[0], $parts[1]);
        }

        return implode(', ', $parts);
    }

    /**
     * Jobs that are enabled and due now (next_run_at <= $now, UTC).
     *
     * @return list<object>
     */
    public static function dueJobs(string $now): array {
        return DB::table('cj_job')
            ->where('enabled', 1)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->get()
            ->all();
    }

    /**
     * Enabled event-triggered jobs matching one event name (phase 2, §6.1).
     *
     * @return list<object>
     */
    public static function eventJobs(string $event_name): array {
        return DB::table('cj_job')
            ->where('enabled', 1)
            ->where('trigger_type', '=', self::TRIGGER_EVENT)
            ->where('event_name', '=', $event_name)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return object|null
     */
    public static function findJob(string $name): ?object {
        return DB::table('cj_job')->where('name', '=', $name)->first();
    }

    /**
     * Insert a new 'running' run row. Returns the new run id.
     */
    public static function startRun(int $job_id, string $trigger, string $started_at): int {
        return (int) DB::table('cj_run')->insertGetId([
            'job_id'     => $job_id,
            'trigger'    => $trigger,
            'started_at' => $started_at,
            'status'     => self::STATUS_RUNNING,
        ]);
    }

    /**
     * Close a run row after the child process finished.
     */
    public static function finishRun(int $run_id, string $status, int $exit_code, int $duration_ms, string $output, string $finished_at): void {
        DB::table('cj_run')->where('id', '=', $run_id)->update([
            'status'      => $status,
            'exit_code'   => $exit_code,
            'duration_ms' => $duration_ms,
            'output'      => $output,
            'finished_at' => $finished_at,
        ]);
    }

    /**
     * After a run: update the job's last_* fields and recompute next_run_at.
     */
    public static function updateJobAfterRun(object $job, string $now, string $status, int $exit_code): void {
        try {
            $next = self::nextRun((string) $job->cron, $now);
        } catch (DomainException | RuntimeException) {
            // Cron became unparseable - stop scheduling this job.
            $next = null;
        }

        DB::table('cj_job')->where('id', '=', (int) $job->id)->update([
            'last_run_at' => $now,
            'last_exit'   => $exit_code,
            'last_status' => $status,
            'next_run_at' => $next,
            'updated_at'  => $now,
        ]);
    }

    /**
     * Mark 'running' rows that can no longer have a live process
     * (tick died, server restart, ...).
     */
    public static function markStuckRuns(string $now): void {
        $threshold = date('Y-m-d H:i:s', strtotime($now) - self::STUCK_AFTER_SECONDS);

        DB::table('cj_run')
            ->where('status', '=', self::STATUS_RUNNING)
            ->where('started_at', '<', $threshold)
            ->update([
                'status'      => self::STATUS_TIMEOUT,
                'finished_at' => $now,
                'exit_code'   => -9,
            ]);
    }

    /**
     * Keep the history small: last RUN_RETENTION_PER_JOB runs per job
     * and nothing older than RUN_RETENTION_DAYS.
     */
    public static function trimHistory(string $now): void {
        $threshold = date('Y-m-d H:i:s', strtotime($now) - self::RUN_RETENTION_DAYS * 86400);

        // Older than the global retention window.
        DB::table('cj_run')->where('started_at', '<', $threshold)->delete();

        // Beyond the per-job retention count (oldest rows first).
        $job_ids = DB::table('cj_run')->select('job_id')->groupBy('job_id')->pluck('job_id');
        foreach ($job_ids as $job_id) {
            $count  = (int) DB::table('cj_run')->where('job_id', '=', $job_id)->count();
            $excess = $count - self::RUN_RETENTION_PER_JOB;
            if ($excess <= 0) {
                continue;
            }
            $ids = DB::table('cj_run')
                ->where('job_id', '=', $job_id)
                ->orderBy('id')
                ->limit($excess)
                ->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('cj_run')->whereIn('id', $ids->all())->delete();
            }
        }
    }

    /**
     * The current time in the storage basis (UTC).
     */
    public static function now(): string {
        return (new \DateTime("now", new DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    // =========================================================================
    // External job self-registration (phase 2, §6.3)
    // =========================================================================

    /**
     * Normalize + validate a job spec offered by an external module (a
     * <module>/cron-jobs.php manifest or a getCronJobs() marker method).
     *
     * The spec is a plain array - deliberately NOT a shared class, so offering
     * modules never reference the cronjob namespace. Returns the normalized
     * spec plus any validation errors; when errors is empty the spec is ready
     * to be upserted. W1: `command` is an ordinary module/core command (the
     * W1 wrapper derives its payload from the entry script, so there is no
     * `--logic` path to confine here).
     *
     * @param array<string, mixed>  $spec
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    public static function validateJobSpec(array $spec, ?string $root_dir = null): array {
        $root_dir ??= Webtrees::ROOT_DIR;
        $errors     = [];

        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '' || strlen($name) > 64 || preg_match('/^[a-z0-9_\-]+$/', $name) !== 1) {
            $errors[] = 'name must be a non-empty slug of [a-z0-9_-], max 64 chars';
        }

        $title = trim((string) ($spec['title'] ?? ''));
        if ($title === '') {
            $title = $name;
        }
        if (strlen($title) > 128) {
            $errors[] = 'title must be at most 128 chars';
        }

        $trigger_type = (string) ($spec['trigger_type'] ?? self::TRIGGER_TIME);
        if (!in_array($trigger_type, [self::TRIGGER_TIME, self::TRIGGER_EVENT], true)) {
            $errors[] = "trigger_type '$trigger_type' is not supported ('time' or 'event')";
        }

        $event_name = trim((string) ($spec['event_name'] ?? ''));
        if ($trigger_type === self::TRIGGER_EVENT && preg_match(EventQueue::NAME_PATTERN, $event_name) !== 1) {
            $errors[] = 'event jobs require an event_name slug of [a-z0-9_-] (max 64)';
        }

        $cron = trim((string) ($spec['cron'] ?? ''));
        if ($trigger_type === self::TRIGGER_TIME) {
            if ($cron === '' || strlen($cron) > 64) {
                $errors[] = 'cron must be a non-empty expression of at most 64 chars';
            } else {
                try {
                    self::parse($cron);
                } catch (DomainException | RuntimeException $exception) {
                    $errors[] = 'invalid cron expression: ' . $exception->getMessage();
                }
            }
        }

        $command_type = (string) ($spec['command_type'] ?? '');
        if (!in_array($command_type, ['module', 'core'], true)) {
            $errors[] = "command_type must be 'module' or 'core'";
        }

        $command = trim((string) ($spec['command'] ?? ''));
        $args    = trim((string) ($spec['args'] ?? ''));
        if (in_array($command_type, ['module', 'core'], true)) {
            $built = JobRunner::buildArgv(
                ['command_type' => $command_type, 'command' => $command, 'args' => $args],
                $root_dir
            );
            if ($built['error'] !== '') {
                $errors[] = $built['error'];
            }
        }

        $timeout = (int) ($spec['timeout_sec'] ?? self::TIMEOUT_STD);
        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            $errors[] = 'timeout_sec must be between ' . self::TIMEOUT_MIN . ' and ' . self::TIMEOUT_MAX;
            $timeout = self::TIMEOUT_STD;
        }

        $enabled    = (bool) ($spec['enabled'] ?? false);
        $is_event   = $trigger_type === self::TRIGGER_EVENT;
        $final_type = $is_event ? self::TRIGGER_EVENT : self::TRIGGER_TIME;

        return [
            'spec'   => [
                'name'         => $name,
                'title'        => $title,
                'trigger_type' => $final_type,
                'event_name'   => $is_event ? $event_name : null,
                'cron'         => $is_event ? '' : $cron,
                'command_type' => $command_type,
                'command'      => $command,
                'args'         => $args,
                'enabled'      => $enabled,
                'timeout_sec'  => $timeout,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Discover jobs offered by other enabled modules: via a
     * <module>/cron-jobs.php manifest file and/or a getCronJobs() marker
     * method. Returns one entry per valid spec:
     * `['module' => <short>, 'spec' => normalized]`. Malformed or throwing
     * sources are skipped - they must never break the tick.
     *
     * @return list<array{module: string, spec: array<string, mixed>}>
     */
    public static function discoverExternalJobs(?string $root_dir = null): array {
        $root_dir ??= Webtrees::ROOT_DIR;
        $found    = [];

        try {
            $modules = Registry::container()->get(ModuleService::class)->all(false);
        } catch (Throwable) {
            return $found;
        }

        /** @var ModuleInterface $module */
        foreach ($modules as $module) {
            // MODULES_DIR is absolute with a trailing slash; a custom module's
            // directory is exactly its name minus the surrounding underscores.
            // Core modules have no modules_v4/<name>/ dir, so is_file() below
            // filters them out naturally - no separate custom-module guard.
            $short = trim($module->name(), '_');
            $dir   = Webtrees::MODULES_DIR . $short . DIRECTORY_SEPARATOR;

            $raw_specs = [];
            $manifest  = $dir . 'cron-jobs.php';
            if (is_file($manifest)) {
                $raw_specs = array_merge($raw_specs, CronjobUtils::loadManifestFile($manifest));
            }
            if (method_exists($module, 'getCronJobs')) {
                try {
                    $offered = $module->getCronJobs();
                } catch (Throwable) {
                    $offered = [];
                }
                if (is_array($offered)) {
                    $raw_specs = array_merge($raw_specs, array_values($offered));
                }
            }

            foreach ($raw_specs as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $result = self::validateJobSpec($raw, $root_dir);
                if ($result['errors'] === []) {
                    $found[] = ['module' => $short, 'spec' => $result['spec']];
                }
            }
        }

        return $found;
    }

    /**
     * The spec currently offered for an already-adopted job (name =
     * <module>:<name>), or null if no enabled module offers it any more.
     * Backs the admin "reset to module defaults" action.
     *
     * @param list<array{module: string, spec: array<string, mixed>}>|null $offered
     *                                                                       discovery result (injected for tests)
     * @return array<string, mixed>|null normalized spec
     */
    public static function findOfferedSpec(string $job_name, ?array $offered = null): ?array {
        $offered ??= self::discoverExternalJobs();

        foreach ($offered as $entry) {
            $key = trim((string) $entry['module'], '_') . ':' . (string) ($entry['spec']['name'] ?? '');
            if ($key === $job_name) {
                return $entry['spec'];
            }
        }

        return null;
    }

    /**
     * Ensure a discovered job exists in cj_job, keyed as <module>:<name>.
     *
     * Insert-if-missing: once created the row is admin-owned and is never
     * overwritten by the manifest, so admin edits to cron/args/enabled are
     * safe across ticks. A key longer than cj_job.name (VARCHAR 64) is
     * skipped rather than silently truncated (collision risk).
     */
    public static function upsertDiscoveredJob(string $module, array $spec, string $now): void {
        $key = trim($module, '_') . ':' . (string) $spec['name'];
        if (strlen($key) === 0 || strlen($key) > 64 || self::findJob($key) !== null) {
            return;
        }

        $trigger    = ((string) $spec['trigger_type']) === self::TRIGGER_EVENT ? self::TRIGGER_EVENT : self::TRIGGER_TIME;
        $event_name = (string) ($spec['event_name'] ?? '');

        $next = null;
        if ($trigger === self::TRIGGER_TIME) {
            try {
                $next = self::nextRun((string) $spec['cron'], $now);
            } catch (DomainException | RuntimeException) {
                $next = null; // validated already; defensive only
            }
        }

        DB::table('cj_job')->insert([
            'name'         => $key,
            'title'        => (string) $spec['title'],
            'trigger_type' => $trigger,
            'event_name'   => $trigger === self::TRIGGER_EVENT ? $event_name : null,
            'cron'         => (string) $spec['cron'],
            'command_type' => (string) $spec['command_type'],
            'command'      => (string) $spec['command'],
            'args'         => (string) $spec['args'],
            'enabled'      => ($spec['enabled'] ? 1 : 0),
            'timeout_sec'  => (int) $spec['timeout_sec'],
            'next_run_at'  => $next,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * Sync externally-offered jobs into cj_job. Called by the tick; cheap (a
     * filesystem glob + a few inserts/updates at most).
     *
     * Jobs offered by OTHER modules are insert-if-missing (admin-owned once
     * created). cronjob's OWN job is force-synced to its manifest every tick,
     * so a module update (manifest change) converges within one tick.
     */
    public static function syncDiscoveredJobs(): void {
        $now = self::now();
        foreach (self::discoverExternalJobs() as $entry) {
            if (trim((string) $entry['module'], '_') === trim(CronjobUtils::MODULE_NAME, '_')) {
                self::syncOwnOfferedJob($entry['spec'], $now);
            } else {
                self::upsertDiscoveredJob($entry['module'], $entry['spec'], $now);
            }
        }
    }

    /**
     * The cj_job columns a manifest controls, normalized to DB-ready values.
     * The admin keeps ownership of name/enabled/notify/created_at and the run
     * history - those are never force-synced.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function mirrorValues(array $spec): array {
        $is_event = ((string) ($spec['trigger_type'] ?? self::TRIGGER_TIME)) === self::TRIGGER_EVENT;

        return [
            'title'        => (string) ($spec['title'] ?? ''),
            'trigger_type' => $is_event ? self::TRIGGER_EVENT : self::TRIGGER_TIME,
            'event_name'   => $is_event ? (string) ($spec['event_name'] ?? '') : null,
            'cron'         => (string) ($spec['cron'] ?? ''),
            'command_type' => (string) ($spec['command_type'] ?? ''),
            'command'      => (string) ($spec['command'] ?? ''),
            'args'         => (string) ($spec['args'] ?? ''),
            'timeout_sec'  => (int) ($spec['timeout_sec'] ?? self::TIMEOUT_STD),
        ];
    }

    /**
     * The mirrored fields (see mirrorValues()) whose stored value differs from
     * the offered spec. Pure and side-effect free (standalone-testable).
     *
     * @param array<string, mixed> $row  current cj_job values (the mirrored fields)
     * @param array<string, mixed> $spec normalized offered spec
     *
     * @return list<string> field names that differ
     */
    public static function specDiff(array $row, array $spec): array {
        $want = self::mirrorValues($spec);
        $diff = [];
        foreach ($want as $field => $value) {
            $have = $row[$field] ?? null;
            $have = ($field === 'timeout_sec') ? (int) $have : (string) $have;
            $ref  = ($field === 'timeout_sec') ? (int) $value : (string) $value;
            if ($have !== $ref) {
                $diff[] = $field;
            }
        }

        return $diff;
    }

    /**
     * Re-sync cronjob's OWN offered job (keyed <selfmodule>:<name>) to the
     * current manifest. Insert-if-missing; when the row exists, update only
     * the manifest-mirrored fields that changed - the admin's name/enabled/
     * notify/created_at and the run history are preserved, and next_run_at is
     * only rescheduled when the cron expression itself changed.
     *
     * @param array<string, mixed> $spec normalized offered spec
     */
    public static function syncOwnOfferedJob(array $spec, string $now): void {
        $key = trim(CronjobUtils::MODULE_NAME, '_') . ':' . (string) ($spec['name'] ?? '');
        if ($key === '' || strlen($key) > 64) {
            return;
        }

        $job = self::findJob($key);
        if ($job === null) {
            self::upsertDiscoveredJob(CronjobUtils::MODULE_NAME, $spec, $now);

            return;
        }

        $row  = [
            'title'        => (string) $job->title,
            'trigger_type' => (string) $job->trigger_type,
            'event_name'   => (string) ($job->event_name ?? ''),
            'cron'         => (string) $job->cron,
            'command_type' => (string) $job->command_type,
            'command'      => (string) $job->command,
            'args'         => (string) $job->args,
            'timeout_sec'  => (int) $job->timeout_sec,
        ];
        $diff = self::specDiff($row, $spec);
        if ($diff === []) {
            return;
        }

        $values         = array_intersect_key(self::mirrorValues($spec), array_flip($diff));
        $values['updated_at'] = $now;

        // Reschedule only when the schedule itself changed.
        if (in_array('cron', $diff, true) || in_array('trigger_type', $diff, true)) {
            if ((string) $spec['trigger_type'] === self::TRIGGER_TIME) {
                try {
                    $values['next_run_at'] = self::nextRun((string) $spec['cron'], $now);
                } catch (DomainException | RuntimeException) {
                    $values['next_run_at'] = null;
                }
            }
        }

        DB::table('cj_job')->where('id', '=', (int) $job->id)->update($values);
    }
}
