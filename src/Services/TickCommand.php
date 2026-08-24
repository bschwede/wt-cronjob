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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
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
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with code 1 if any job failed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dry_run  = (bool) $input->getOption('dry-run');
        $job_name = $input->getOption('job');
        $strict   = (bool) $input->getOption('strict');

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

            if (is_string($job_name) && trim($job_name) !== '') {
                $job    = ScheduleService::findJob(trim($job_name));
                $jobs   = $job === null ? [] : [$job];
                $trigger = 'manual';
                if ($job === null) {
                    $output->writeln('<error>job not found: ' . trim($job_name) . '</error>');

                    return 1;
                }
            } else {
                $jobs    = ScheduleService::dueJobs($now);
                $trigger = 'schedule';
            }

            if ($jobs === []) {
                $output->writeln('no jobs due');

                return 0;
            }

            $failures = 0;
            foreach ($jobs as $job) {
                $output->writeln('job ' . $job->name . ':');

                $built = JobRunner::buildArgv((array) $job, Webtrees::ROOT_DIR);
                if ($built['error'] !== '') {
                    $output->writeln('  <error>invalid job definition: ' . $built['error'] . '</error>');
                    $failures++;
                    // Move the schedule forward so a broken job does not
                    // re-trigger every minute.
                    ScheduleService::updateJobAfterRun($job, ScheduleService::now(), ScheduleService::STATUS_ERROR, -1);
                    continue;
                }

                if ($dry_run) {
                    $output->writeln('  dry-run: ' . implode(' ', $built['argv']));
                    continue;
                }

                $started_at  = ScheduleService::now();
                $run_id      = ScheduleService::startRun((int) $job->id, $trigger, $started_at);
                $start_micro = microtime(true);

                $result = JobRunner::run($built['argv'], (int) $job->timeout_sec, Webtrees::ROOT_DIR);

                $duration_ms = (int) round((microtime(true) - $start_micro) * 1000);
                $status      = $result['timed_out']
                    ? ScheduleService::STATUS_TIMEOUT
                    : ($result['exit'] === 0 ? ScheduleService::STATUS_OK : ScheduleService::STATUS_ERROR);

                $done = ScheduleService::now();
                ScheduleService::finishRun($run_id, $status, $result['exit'], $duration_ms, $result['output'], $done);
                ScheduleService::updateJobAfterRun($job, $done, $status, $result['exit']);

                if ($result['output'] !== '') {
                    foreach (explode("\n", trim($result['output'])) as $line) {
                        $output->writeln('    ' . $line);
                    }
                }
                $output->writeln(sprintf('  %s (exit %d, %d ms)', $status, $result['exit'], $duration_ms));

                if ($status !== ScheduleService::STATUS_OK) {
                    $failures++;
                }
            }

            if (!$dry_run) {
                ScheduleService::trimHistory(ScheduleService::now());
            }

            return ($strict && $failures > 0) ? 1 : 0;
        } finally {
            CliBootstrap::releaseTickLock($lock);
        }
    }
}
