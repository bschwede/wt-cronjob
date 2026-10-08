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
 * MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\Cronjob\Services;

use DateTimeZone;
use Fisharebest\Webtrees\DB;

use function date;
use function ksort;
use function strtotime;

/**
 * The single database access layer for the cj_job / cj_run / cj_job_trigger
 * tables (job registry, run history, trigger rows).
 *
 * Every cj_* query in the module lives here - no other class talks to these
 * tables (enforced by tests/test-db-boundary.php). The methods moved here
 * from ScheduleService / CronjobUtils / CronjobModule / CronjobService are
 * verbatim; see the git history of those files.
 *
 * All stored timestamps use UTC 'Y-m-d H:i:s' - the same basis the webtrees
 * core uses (Webtrees::bootstrap() sets the default timezone to UTC), so
 * tick and admin UI always agree.
 */
final class JobRepository {

    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_ERROR   = 'error';
    public const STATUS_TIMEOUT = 'timeout';

    public const RUN_RETENTION_PER_JOB = 25;
    public const RUN_RETENTION_DAYS    = 30;
    /** A 'running' row older than this can no longer have a live process. */
    public const STUCK_AFTER_SECONDS   = 3660;

    // =========================================================================
    // Time
    // =========================================================================

    /**
     * The current time in the storage basis (UTC).
     */
    public static function now(): string {
        return (new \DateTime("now", new DateTimeZone(CronExpressionService::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    // =========================================================================
    // Schema
    // =========================================================================

    /**
     * Are the job registry tables present (cj_job + cj_run)?
     */
    public static function isMigrated(): bool {
        return DB::schema()->hasTable('cj_job') && DB::schema()->hasTable('cj_run');
    }

    // =========================================================================
    // Jobs
    // =========================================================================

    /**
     * All jobs plus the data of their most recent run.
     *
     * @return list<object>
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
     * The names of all jobs, sorted.
     *
     * @return list<string>
     */
    public static function jobNames(): array {
        return DB::table('cj_job')->orderBy('name')->pluck('name')->all();
    }

    /**
     * @return object|null
     */
    public static function findById(int $id): ?object {
        return DB::table('cj_job')->where('id', '=', $id)->first();
    }

    /**
     * @return object|null
     */
    public static function findByName(string $name): ?object {
        return DB::table('cj_job')->where('name', '=', $name)->first();
    }

    /**
     * Is a job name (slug) already taken?
     */
    public static function nameExists(string $name): bool {
        return DB::table('cj_job')->where('name', '=', $name)->exists();
    }

    /**
     * Insert a job row. Returns the new id.
     *
     * @param array<string, mixed> $values
     */
    public static function insertJob(array $values): int {
        return (int) DB::table('cj_job')->insertGetId($values);
    }

    /**
     * Update a job row by id.
     *
     * @param array<string, mixed> $values
     */
    public static function updateJobById(int $job_id, array $values): void {
        DB::table('cj_job')->where('id', '=', $job_id)->update($values);
    }

    /**
     * Insert or update a job row. $data keys: name, title, triggers
      * (normalized list, see TriggerService::normalizeTriggers()),
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
            'next_run_at'  => TriggerService::nextRunMin($triggers, $now),
            'updated_at'   => $now,
        ];

        if ($id > 0) {
            self::updateJobById($id, $values + [
                'name' => $data['name'],
            ]);
        } else {
            $id = self::insertJob($values + [
                'name'       => $data['name'],
                'created_at' => $data['created_at'] ?? $now,
            ]);
        }
        self::replaceJobTriggers($id, $triggers);
    }

    /**
     * Delete a job (run history is removed by the FK cascade).
     */
    public static function deleteJob(int $id): void {
        DB::table('cj_job')->where('id', '=', $id)->delete();
    }

    /**
     * Queue a job for the next tick (same semantics as the admin "Run now"):
     * next_run_at is set to now.
     */
    public static function queueForNextTick(int $job_id, string $now): void {
        DB::table('cj_job')->where('id', '=', $job_id)->update([
            'next_run_at' => $now,
            'updated_at'  => $now,
        ]);
    }

    /**
     * Enable/disable a job. A disabled job keeps its next_run_at; on
     * re-enable the single catch-up run happens (no burst).
     */
    public static function setEnabled(int $job_id, int $enabled, string $now): void {
        DB::table('cj_job')->where('id', '=', $job_id)->update([
            'enabled'    => $enabled,
            'updated_at' => $now,
        ]);
    }

    /**
     * Enable/disable failure notification for a job.
     */
    public static function setNotify(int $job_id, int $notify, string $now): void {
        DB::table('cj_job')->where('id', '=', $job_id)->update([
            'notify'     => $notify,
            'updated_at' => $now,
        ]);
    }

    // =========================================================================
    // Triggers
    // =========================================================================

    /**
     * The trigger rows of one job (§13), in the canonical shape used by
     * normalizeTriggers()/nextRunMin()/replaceJobTriggers().
     *
     * @return list<array{type: string, cron: string, event: string}>
     */
    public static function jobTriggers(int $job_id): array {
        $triggers = [];
        foreach (DB::table('cj_job_trigger')->where('job_id', '=', $job_id)->get() as $row) {
            $triggers[] = [
                'type'  => (string) $row->trigger_type,
                'cron'  => (string) ($row->cron ?? ''),
                'event' => (string) ($row->event_name ?? ''),
            ];
        }

        return $triggers;
    }

    /**
     * Replace all trigger rows of a job (§13) - delete + insert, in one go.
     * The caller passes an already-validated, normalized trigger list.
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     */
    public static function replaceJobTriggers(int $job_id, array $triggers): void {
        DB::table('cj_job_trigger')->where('job_id', '=', $job_id)->delete();
        foreach ($triggers as $trigger) {
            DB::table('cj_job_trigger')->insert([
                'job_id'       => $job_id,
                'trigger_type' => (string) $trigger['type'],
                'cron'         => $trigger['type'] === TriggerService::TRIGGER_TIME ? (string) $trigger['cron'] : null,
                'event_name'   => $trigger['type'] === TriggerService::TRIGGER_EVENT ? (string) $trigger['event'] : null,
            ]);
        }
    }

    /**
     * All trigger rows grouped by job id (§13) - one query for the admin table.
     *
     * @return array<int, list<array{type: string, cron: string, event: string}>>
     */
    public static function allJobTriggers(): array {
        $grouped = [];
        foreach (DB::table('cj_job_trigger')->get() as $row) {
            $grouped[(int) $row->job_id][] = [
                'type'  => (string) $row->trigger_type,
                'cron'  => (string) ($row->cron ?? ''),
                'event' => (string) ($row->event_name ?? ''),
            ];
        }

        return $grouped;
    }

    // =========================================================================
    // Event triggers (the cj_job ⋈ cj_job_trigger lookups)
    // =========================================================================

    /**
     * Enabled event-triggered jobs matching one event name (phase 2, §6.1;
     * §13: the trigger lives in cj_job_trigger, so a job with several event
     * triggers matches each of them).
     *
     * @return list<object>
     */
    public static function jobsForEvent(string $event_name): array {
        return DB::table('cj_job')
            ->join('cj_job_trigger', 'cj_job_trigger.job_id', '=', 'cj_job.id')
            ->where('cj_job.enabled', 1)
            ->where('cj_job_trigger.trigger_type', '=', TriggerService::TRIGGER_EVENT)
            ->where('cj_job_trigger.event_name', '=', $event_name)
            ->orderBy('cj_job.id')
            ->select('cj_job.*')
            ->get()
            ->all();
    }

    /**
     * Does any enabled event-triggered job listen for one event name?
     */
    public static function hasListenerFor(string $event): bool {
        return DB::table('cj_job')
            ->join('cj_job_trigger', 'cj_job_trigger.job_id', '=', 'cj_job.id')
            ->where('cj_job.enabled', 1)
            ->where('cj_job_trigger.trigger_type', '=', TriggerService::TRIGGER_EVENT)
            ->where('cj_job_trigger.event_name', '=', $event)
            ->exists();
    }

    /**
     * Distinct event names of enabled event-triggered jobs (webhook-only
     * names included).
     *
     * @return list<string>
     */
    public static function enabledEventNames(): array {
        return DB::table('cj_job_trigger')
            ->join('cj_job', 'cj_job.id', '=', 'cj_job_trigger.job_id')
            ->where('cj_job.enabled', 1)
            ->where('cj_job_trigger.trigger_type', '=', TriggerService::TRIGGER_EVENT)
            ->whereNotNull('cj_job_trigger.event_name')
            ->distinct()
            ->pluck('cj_job_trigger.event_name')
            ->all();
    }

    /**
     * The events at least one enabled event-triggered job listens for:
     * event name => the listening job names (sorted by event name).
     *
     * @return array<string, list<string>>
     */
    public static function listenedEvents(): array {
        $map  = [];
        $rows = DB::table('cj_job_trigger')
            ->join('cj_job', 'cj_job.id', '=', 'cj_job_trigger.job_id')
            ->where('cj_job.enabled', '=', 1)
            ->where('cj_job_trigger.trigger_type', '=', TriggerService::TRIGGER_EVENT)
            ->whereNotNull('cj_job_trigger.event_name')
            ->select('cj_job_trigger.event_name', 'cj_job.name')
            ->get()
            ->all();

        foreach ($rows as $row) {
            $map[(string) $row->event_name][] = (string) $row->name;
        }
        ksort($map);

        return $map;
    }

    // =========================================================================
    // Tick pipeline
    // =========================================================================

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
     * Self-heal for stranded schedule jobs: an enabled job with a time
     * trigger whose next_run_at is NULL is never picked up by dueJobs()
     * again (it filters on next_run_at IS NOT NULL). That state can arise
     * after a code/DB restore, a manual DB edit or a crash - e.g. when a
     * run happened while the cron library was missing. Such jobs are
     * re-scheduled at their NEXT slot (no catch-up flood). No-op while the
     * cron library is missing (nextRunMin() then yields NULL).
     *
     * @return int number of repaired jobs
     */
    public static function repairStrandedSchedules(string $now): int {
        $repaired = 0;
        $stranded = DB::table('cj_job')
            ->where('enabled', 1)
            ->whereNull('next_run_at')
            ->get()
            ->all();

        foreach ($stranded as $job) {
            $next = TriggerService::nextRunForRepair(self::jobTriggers((int) $job->id), $now);
            if ($next !== null) {
                self::updateJobById((int) $job->id, [
                    'next_run_at' => $next,
                    'updated_at'  => $now,
                ]);
                $repaired++;
            }
        }

        return $repaired;
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

    // =========================================================================
    // Runs
    // =========================================================================

    /**
     * Insert a new 'running' run row. Returns the new run id.
     */
    public static function startRun(int $job_id, string $trigger, string $started_at, string $trigger_detail = ''): int {
        return (int) DB::table('cj_run')->insertGetId([
            'job_id'         => $job_id,
            'trigger'        => $trigger,
            'trigger_detail' => $trigger_detail !== '' ? $trigger_detail : null,
            'started_at'     => $started_at,
            'status'         => self::STATUS_RUNNING,
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
     * After a run: update the job's last_* fields and recompute next_run_at
     * as the minimum over the job's time triggers (§13; NULL when the job
     * has no time trigger). While the cron library is missing, next_run_at
     * is left untouched: writing NULL would silently strand the job
     * (dueJobs() skips NULL), while the stale value keeps it running - a
     * visible, self-healing degraded state.
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     */
    public static function updateJobAfterRun(object $job, array $triggers, string $now, string $status, int $exit_code): void {
        $values = [
            'last_run_at' => $now,
            'last_exit'   => $exit_code,
            'last_status' => $status,
            'updated_at'  => $now,
        ];

        if (CronExpressionService::hasCronLibrary()) {
            $values['next_run_at'] = TriggerService::nextRunMin($triggers, $now);
        }

        self::updateJobById((int) $job->id, $values);
    }

    /**
     * The number of runs of one job (all time).
     */
    public static function countRuns(int $job_id): int {
        return (int) DB::table('cj_run')->where('job_id', '=', $job_id)->count();
    }

    /**
     * One page of the run history of a job, newest first.
     *
     * @return list<object>
     */
    public static function pagedRuns(int $job_id, int $page, int $per_page): array {
        return DB::table('cj_run')
            ->where('job_id', '=', $job_id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($per_page)
            ->offset(($page - 1) * $per_page)
            ->get()
            ->all();
    }

    /**
     * The most recent run of a job, or null when it never ran.
     * Deliberately the raw row - the caller maps it (CronjobService::lastRun()
     * excludes the output column, which may contain personal data).
     *
     * @return object|null
     */
    public static function lastRunFor(int $job_id): ?object {
        return DB::table('cj_run')
            ->where('job_id', '=', $job_id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

}
