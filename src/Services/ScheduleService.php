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
use RuntimeException;

use function class_exists;
use function date;
use function max;
use function strtotime;

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
     * @throws DomainException|RuntimeException
     */
    public static function parse(string $cron): CronExpression {
        if (!self::hasCronLibrary()) {
            throw new RuntimeException(self::cronLibraryMissingMessage());
        }
        return new CronExpression($cron);
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
            ->getNextRunDate(new DateTimeImmutable($from, new DateTimeZone(self::TIMEZONE)));

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
            $cursor = $cron->getNextRunDate($cursor);
            $runs[] = $cursor->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
        }

        return $runs;
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
}
