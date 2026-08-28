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

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function function_exists;
use function implode;
use function ini_get;
use function in_array;
use function is_string;
use function microtime;
use function sprintf;
use function trim;

/**
 * The once-a-minute trigger. Cheap when nothing is due (a few ms):
 *
 *   guard -> offline gate (in tick.php, before this) -> boot
 *   -> module enabled? -> tables exist? -> flock
 *   -> mark stuck runs -> run due jobs sequentially -> trim history
 *
 * Exit codes: 0 = tick ran (job failures are recorded in the DB),
 * 1 = environment problem or (with --strict) at least one job failed.
 */
final class TickCommand extends Command {

    protected function configure(): void {
        $this->setName('cron:tick')
            ->setDescription('Run the due jobs of the webtrees cronjob module')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show due jobs and their argv without executing')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run a specific job by name, immediately (manual trigger)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with code 1 if any job failed')
            ->addOption('full-output', null, InputOption::VALUE_NONE, 'Echo the full job output to stdout (default: summary lines only - job output may contain personal data and must not leak into world-readable log files)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dry_run      = (bool) $input->getOption('dry-run');
        $job_name     = $input->getOption('job');
        $strict       = (bool) $input->getOption('strict');
        $full_output  = (bool) $input->getOption('full-output');

        // proc_open may be disabled (shared hosting) - fail clearly.
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            $output->writeln('<error>proc_open() is not available - the tick cannot run jobs.</error>');

            return 1;
        }

        // Kill switch: the module can be disabled from the admin UI.
        $status = DB::table('module')->where('module_name', '=', CronjobUtils::MODULE_NAME)->value('status');
        if ($status !== 'enabled') {
            $output->writeln('module disabled - nothing to do');

            return 0;
        }

        // The tables are created by the module's boot() on the first HTTP request.
        if (!DB::schema()->hasTable('cj_job') || !DB::schema()->hasTable('cj_run')) {
            $output->writeln('cronjob tables missing - open webtrees once so the module can create them');

            return 0;
        }

        $lock = CliBootstrap::acquireTickLock();
        if ($lock === null) {
            $output->writeln('another tick is already running - skipped');

            return 0;
        }

        try {
            $now = ScheduleService::now();
            ScheduleService::markStuckRuns($now);

            // Sync externally-offered jobs into cj_job (insert-if-missing,
            // keyed <module>:<name>) and the event/command inventories
            // (catalogs). Skipped under --dry-run so a dry run has no side
            // effects on the registries.
            if (!$dry_run) {
                ScheduleService::syncDiscoveredJobs();
                try {
                    EventCatalogService::syncCatalog($now);
                } catch (Throwable $exception) {
                    $output->writeln('<error>event catalog sync failed: ' . $exception->getMessage() . '</error>');
                }
                try {
                    CommandCatalogService::syncCatalog($now);
                } catch (Throwable $exception) {
                    $output->writeln('<error>command catalog sync failed: ' . $exception->getMessage() . '</error>');
                }
            }

            $failures = 0;

            if (is_string($job_name) && trim($job_name) !== '') {
                // Manual single-job trigger (--job): run just that job.
                $job = ScheduleService::findJob(trim($job_name));
                if ($job === null) {
                    $output->writeln('<error>job not found: ' . trim($job_name) . '</error>');

                    return 1;
                }
                $output->writeln('job ' . $job->name . ':');
                $result = $this->runOneJob($job, 'manual', $input, $output);
                $failures += $result['ok'] ? 0 : 1;
            } else {
                // Scheduled tick: due time-based jobs, then the event queue.
                $jobs = ScheduleService::dueJobs($now);
                if ($jobs === []) {
                    $output->writeln('no time-based jobs due');
                }
                foreach ($jobs as $job) {
                    $output->writeln('job ' . $job->name . ':');
                    $result = $this->runOneJob($job, 'schedule', $input, $output);
                    $failures += $result['ok'] ? 0 : 1;
                }

                $failures += $this->drainEvents($input, $output);
            }

            if (!$dry_run) {
                ScheduleService::trimHistory(ScheduleService::now());
                EventQueue::purge(ScheduleService::now());
            }

            return ($strict && $failures > 0) ? 1 : 0;
        } finally {
            CliBootstrap::releaseTickLock($lock);
        }
    }

    /**
     * Run one job (shared by the time-based loop and the event queue):
     * definition check, dry-run, execution, history, failure notification.
     *
     * $trigger_detail names what fired the run (§13): for schedule runs the
     * due cron expression(s) - derived from the triggers, defaulting to ''
     * when nothing can be determined (e.g. a "run now" queue).
     *
     * @return array{run_id: int, ok: bool}
     */
    private function runOneJob(object $job, string $trigger, InputInterface $input, OutputInterface $output, string $trigger_detail = ''): array {
        $dry_run     = (bool) $input->getOption('dry-run');
        $full_output = (bool) $input->getOption('full-output');

        $triggers = ScheduleService::jobTriggers((int) $job->id);

        if ($trigger === 'schedule' && $trigger_detail === '') {
            // Which cron(s) were due since the previous run (anchor: last
            // run, or the job creation for never-run jobs)?
            $anchor = (string) ($job->last_run_at ?? $job->created_at);
            $trigger_detail = implode(' + ', ScheduleService::dueTriggerDetails(
                array_values(array_filter(
                    $triggers,
                    static fn (array $t): bool => $t['type'] === ScheduleService::TRIGGER_TIME
                )),
                $anchor,
                ScheduleService::now()
            ));
        }

        $built = JobRunner::buildArgv((array) $job, Webtrees::ROOT_DIR);
        if ($built['error'] !== '') {
            $output->writeln('  <error>invalid job definition: ' . $built['error'] . '</error>');
            // Move the schedule forward so a broken job does not re-trigger
            // every minute; a broken definition is a failure to report.
            ScheduleService::updateJobAfterRun($job, $triggers, ScheduleService::now(), ScheduleService::STATUS_ERROR, -1);
            NotifyService::notifyJobFailed($job, ScheduleService::STATUS_ERROR, -1, 0, '', ScheduleService::now());

            return ['run_id' => 0, 'ok' => false];
        }

        if ($dry_run) {
            $output->writeln('  dry-run: ' . implode(' ', $built['argv']));

            return ['run_id' => 0, 'ok' => true];
        }

        $started_at  = ScheduleService::now();
        $run_id      = ScheduleService::startRun((int) $job->id, $trigger, $started_at, $trigger_detail);
        $start_micro = microtime(true);

        $result = JobRunner::run($built['argv'], (int) $job->timeout_sec, JobRunner::cwdFor((array) $job, Webtrees::ROOT_DIR));

        $duration_ms = (int) round((microtime(true) - $start_micro) * 1000);
        $status      = $result['timed_out']
            ? ScheduleService::STATUS_TIMEOUT
            : ($result['exit'] === 0 ? ScheduleService::STATUS_OK : ScheduleService::STATUS_ERROR);

        $done = ScheduleService::now();
        ScheduleService::finishRun($run_id, $status, $result['exit'], $duration_ms, $result['output'], $done);
        ScheduleService::updateJobAfterRun($job, $triggers, $done, $status, $result['exit']);

        if ($full_output && $result['output'] !== '') {
            foreach (explode("\n", trim($result['output'])) as $line) {
                $output->writeln('    ' . $line);
            }
        }
        $output->writeln(sprintf('  %s (exit %d, %d ms)', $status, $result['exit'], $duration_ms));

        if ($status !== ScheduleService::STATUS_OK) {
            NotifyService::notifyJobFailed($job, $status, $result['exit'], $duration_ms, $result['output'], $started_at);

            return ['run_id' => $run_id, 'ok' => false];
        }

        return ['run_id' => $run_id, 'ok' => true];
    }

    /**
     * Drain the event queue: for each pending event run its matching
     * event-triggered jobs once, then mark the event handled (consumed).
     * Events with no matching job are discarded so the queue does not grow.
     *
     * @return int number of failed runs
     */
    private function drainEvents(InputInterface $input, OutputInterface $output): int {
        $dry_run  = (bool) $input->getOption('dry-run');
        $failures = 0;

        foreach (EventQueue::pending() as $event) {
            $name    = (string) $event->event_name;
            $matched = ScheduleService::eventJobs($name);

            $output->writeln('event ' . $name . ($matched === [] ? ': no matching job - discarded' : ':'));

            $run_id = 0;
            foreach ($matched as $job) {
                $output->writeln('  job ' . $job->name . ':');
                $result = $this->runOneJob($job, 'event', $input, $output, $name);
                if ($run_id === 0 && $result['run_id'] !== 0) {
                    $run_id = $result['run_id'];
                }
                // Many-to-many audit: every consuming run is linked to the
                // event (handled_run_id only stores the first one).
                if (!$dry_run && $result['run_id'] !== 0) {
                    EventQueue::recordRun((int) $event->id, $result['run_id']);
                }
                $failures += $result['ok'] ? 0 : 1;
            }

            if (!$dry_run) {
                EventQueue::markHandled((int) $event->id, $run_id);
            }
        }

        return $failures;
    }
}
