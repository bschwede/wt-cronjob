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

use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;

use function array_merge;
use function dirname;
use function fclose;
use function fopen;
use function function_exists;
use function is_readable;
use function mb_strlen;
use function preg_match;
use function str_ends_with;
use function strlen;
use function trim;

/**
 * Cron job scheduler service for webtrees.
 *
 * Time-based (cron) and manually triggered maintenance jobs, executed by
 * the once-a-minute tick (cli/tick.php) as isolated child processes.
 * All actions are named *Admin* -> admin-only via ModuleAction.
 */
class CronjobModule extends AbstractModule
    implements
        ModuleConfigInterface,
        ModuleCustomInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;

    public const SCHEMA_TARGET_VERSION = 1;

    public const TIMEOUT_MIN = 30;
    public const TIMEOUT_MAX = 3600;
    public const TIMEOUT_STD = 300;

    // =========================================================================
    // ModuleInterface
    // =========================================================================

    public function title(): string {
        return I18N::translate('Cron Job Scheduler');
    }

    public function description(): string {
        return I18N::translate('Schedule and monitor maintenance jobs: cron expressions, run history and manual trigger.');
    }

    // =========================================================================
    // Boot
    // =========================================================================

    public function boot(): void {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        CronjobUtils::updateSchema($this, self::SCHEMA_TARGET_VERSION);

        // Page-load watchdog for the optional resident watch daemon. Costs a
        // single file_exists() when the watch is not enabled; never throws.
        WatchService::maybeSpawn();
    }

    // =========================================================================
    // ModuleCustomInterface
    // =========================================================================

    public function customModuleAuthorName(): string {
        return 'Schwendinger';
    }

    public function customModuleVersion(): string {
        return '1.0.0';
    }

    /**
     * Translation loader (linkenhancer pattern): loads
     * resources/lang/<sprache>.php or .po when present. Gettext-generated
     * files can therefore be dropped in later WITHOUT code changes.
     */
    public function customTranslations(string $language): array {
        $file_base = $this->resourcesFolder() . 'lang' . DIRECTORY_SEPARATOR . $language;
        $file      = null;
        foreach (['.php', '.po'] as $ext) {
            if (is_readable($file_base . $ext)) {
                $file = $file_base . $ext;
                break;
            }
        }

        if ($file === null) {
            return [];
        }

        if (class_exists('\\Fisharebest\\Webtrees\\I18N\\Translation')) {
            if (str_ends_with($file, '.po')) {
                $stream = fopen($file, 'rb');
                if ($stream === false) {
                    return [];
                }
                try {
                    return \Fisharebest\Webtrees\I18N\Translation::fromPoStream($stream)->toArray();
                } finally {
                    fclose($stream);
                }
            }
            return \Fisharebest\Webtrees\I18N\Translation::fromPhpFile($file)->toArray();
        }

        if (class_exists('\\Fisharebest\\Localization\\Translation')) {
            return (new \Fisharebest\Localization\Translation($file))->asArray();
        }

        return [];
    }

    public function resourcesFolder(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR;
    }

    // =========================================================================
    // Actions (all "Admin*" -> admin-only via ModuleAction)
    // =========================================================================

    /**
     * Job list + trigger installation + banners.
     */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::admin', [
            'title'     => $this->title(),
            'module'    => $this,
            'jobs'      => CronjobUtils::listJobs(),
            'offline'   => CliBootstrap::siteIsOffline(),
            'cron_lib'  => ScheduleService::hasCronLibrary(),
            'cron_now'  => ScheduleService::now(),
            'install'   => CronjobUtils::triggerInstallBlocks(),
            'watch'     => WatchService::status(),
        ]);
    }

    /**
     * Job create/edit form (?job=<id> to edit).
     */
    public function getAdminJobFormAction(ServerRequestInterface $request): ResponseInterface {
        $this->layout = 'layouts/administration';

        $job_id = Validator::queryParams($request)->integer('job', 0);
        $job    = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        $preview = null;
        if ($job !== null && ScheduleService::hasCronLibrary()) {
            try {
                $preview = ScheduleService::upcomingRuns((string) $job->cron, 5, ScheduleService::now());
            } catch (DomainException | RuntimeException) {
                $preview = null;
            }
        }

        return $this->viewResponse($this->name() . '::job-form', [
            'title'      => I18N::translate('Cron Job Scheduler'),
            'module'     => $this,
            'job'        => $job,
            'candidates' => array_merge(CronjobUtils::jobScriptCandidates(), JobRunner::ALLOWED_CORE_COMMANDS),
            'preview'    => $preview,
            'cron_now'   => ScheduleService::now(),
        ]);
    }

    /**
     * Save a job (create or update).
     */
    public function postAdminJobSaveAction(ServerRequestInterface $request): ResponseInterface {
        $data      = Validator::parsedBody($request);
        $name      = trim($data->string('name', ''));
        $title     = trim($data->string('title', ''));
        $cron      = trim($data->string('cron', ''));
        $command   = trim($data->string('command', ''));
        $args      = trim($data->string('args', ''));
        $timeout   = $data->integer('timeout', self::TIMEOUT_STD);
        $enabled   = $data->boolean('enabled', false);
        $job_id    = $data->integer('job_id', 0);

        $errors = [];
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $name) !== 1) {
            $errors[] = I18N::translate('Job name must be a slug: %1$s, max %2$s characters.', 'a-z, 0-9, "_", "-"', '64');
        }
        if ($title === '') {
            $errors[] = I18N::translate('Job title must not be empty.');
        }
        try {
            ScheduleService::validateCron($cron);
        } catch (DomainException | RuntimeException $exception) {
            $errors[] = I18N::translate('Invalid cron expression "%1$s": %2$s', $cron, $exception->getMessage());
        }
        // DB column widths (Migration0): title 128, cron 64, args 255.
        if (mb_strlen($title) > 128) {
            $errors[] = I18N::translate('Job title must be at most %d characters.', 128);
        }
        if (strlen($cron) > 64) {
            $errors[] = I18N::translate('Cron expression must be at most %d characters.', 64);
        }
        if (strlen($args) > 255) {
            $errors[] = I18N::translate('Arguments must be at most %d characters.', 255);
        }

        $command_type = CronjobUtils::detectCommandType($command);
        if ($command_type === '') {
            $errors[] = I18N::translate('Command must be a %1$s path or an allowlisted core command.', 'modules_v4/<module>/cli/<script>.php');
        } else {
            $built = JobRunner::buildArgv([
                'command_type' => $command_type,
                'command'      => $command,
                'args'         => $args,
            ], Webtrees::ROOT_DIR);
            if ($built['error'] !== '') {
                $errors[] = I18N::translate('Invalid command: %s', $built['error']);
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                FlashMessages::addMessage($error, 'danger');
            }
            return redirect(route('module', ['module' => $this->name(), 'action' => 'AdminJobForm', 'job' => $job_id]));
        }

        $existing = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        // Renaming onto another job's slug would overwrite that job.
        $foreign = DB::table('cj_job')->where('name', '=', $name)->first();
        if ($foreign !== null && ($existing === null || (int) $foreign->id !== (int) $existing->id)) {
            FlashMessages::addMessage(I18N::translate('A job with this name already exists.'), 'danger');
            return redirect(route('module', ['module' => $this->name(), 'action' => 'AdminJobForm', 'job' => $job_id]));
        }

        $now = ScheduleService::now();
        CronjobUtils::saveJob([
            'name'         => $name,
            'title'        => $title,
            'cron'         => $cron,
            'command_type' => $command_type,
            'command'      => $command,
            'args'         => $args,
            'timeout_sec'  => max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, $timeout)),
            'enabled'      => $enabled,
            'created_at'   => $existing?->created_at ?? $now,
        ], $now, (int) ($existing?->id ?? 0));

        FlashMessages::addMessage(I18N::translate('Job saved.'), 'success');
        return redirect($this->getConfigLink());
    }

    /**
     * Run history of one job (?job=<id>).
     */
    public function getAdminJobHistoryAction(ServerRequestInterface $request): ResponseInterface {
        $this->layout = 'layouts/administration';

        $job_id   = Validator::queryParams($request)->integer('job', 0);
        $job      = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;
        $page     = max(1, Validator::queryParams($request)->integer('page', 1));
        $per_page = 25;

        $runs  = [];
        $total = 0;
        if ($job !== null) {
            $total = (int) DB::table('cj_run')->where('job_id', '=', $job->id)->count();
            $runs  = DB::table('cj_run')
                ->where('job_id', '=', $job->id)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit($per_page)
                ->offset(($page - 1) * $per_page)
                ->get()
                ->all();
        }

        return $this->viewResponse($this->name() . '::job-history', [
            'title'    => I18N::translate('Run History'),
            'module'   => $this,
            'job'      => $job,
            'runs'     => $runs,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
        ]);
    }

    /**
     * Queue a job for the next tick (<= 60 s).
     */
    public function postAdminJobRunNowAction(ServerRequestInterface $request): ResponseInterface {
        $job_id = Validator::parsedBody($request)->integer('job_id', 0);
        $job    = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        if ($job !== null) {
            $now = ScheduleService::now();
            DB::table('cj_job')->where('id', '=', $job_id)->update([
                'next_run_at' => $now,
                'updated_at'  => $now,
            ]);
            FlashMessages::addMessage(I18N::translate('Job queued - it will run at the next tick (within 60 seconds).'), 'success');
        } else {
            FlashMessages::addMessage(I18N::translate('Job not found.'), 'danger');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Enable/disable a job.
     */
    public function postAdminJobToggleAction(ServerRequestInterface $request): ResponseInterface {
        $job_id = Validator::parsedBody($request)->integer('job_id', 0);
        $job    = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        if ($job !== null) {
            $now     = ScheduleService::now();
            $enabled = (int) $job->enabled === 0 ? 1 : 0;
            DB::table('cj_job')->where('id', '=', $job_id)->update([
                'enabled'    => $enabled,
                // A disabled job keeps its next_run_at; on re-enable the
                // single catch-up run happens (no burst).
                'updated_at' => $now,
            ]);
            FlashMessages::addMessage(
                $enabled ? I18N::translate('Job enabled.') : I18N::translate('Job disabled.'),
                'success'
            );
        } else {
            FlashMessages::addMessage(I18N::translate('Job not found.'), 'danger');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Delete a job (run history is removed by the FK cascade).
     */
    public function postAdminJobDeleteAction(ServerRequestInterface $request): ResponseInterface {
        $job_id = Validator::parsedBody($request)->integer('job_id', 0);
        $job    = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        if ($job !== null) {
            CronjobUtils::deleteJob($job_id);
            FlashMessages::addMessage(I18N::translate('Job deleted.'), 'success');
        } else {
            FlashMessages::addMessage(I18N::translate('Job not found.'), 'danger');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Start the resident watch daemon (opt-in). It then ticks on its own and
     * the page-load watchdog keeps it alive.
     */
    public function postAdminWatchStartAction(ServerRequestInterface $request): ResponseInterface {
        if (!function_exists('proc_open')) {
            FlashMessages::addMessage(I18N::translate('The watch daemon needs proc_open(), which is disabled on this server. Use an OS cron or systemd timer instead.'), 'danger');

            return redirect($this->getConfigLink());
        }

        WatchService::enable();
        if (WatchService::spawn()) {
            FlashMessages::addMessage(I18N::translate('Watch daemon started.'), 'success');
        } else {
            FlashMessages::addMessage(I18N::translate('The watch daemon could not be started (proc_open is disabled or it is already running).'), 'warning');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * Stop the resident watch daemon. It exits within about a minute and the
     * watchdog will not respawn it.
     */
    public function postAdminWatchStopAction(ServerRequestInterface $request): ResponseInterface {
        WatchService::disable();
        FlashMessages::addMessage(I18N::translate('Watch daemon stopped - it exits within about a minute and will not restart.'), 'success');

        return redirect($this->getConfigLink());
    }
}
