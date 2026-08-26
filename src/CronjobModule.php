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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue;
use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;

use function array_merge;
use function bin2hex;
use function dirname;
use function fclose;
use function fopen;
use function function_exists;
use function hash_equals;
use function is_array;
use function is_readable;
use function json_decode;
use function mb_strlen;
use function preg_match;
use function random_bytes;
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
        ModuleCustomInterface,
        MiddlewareInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;

    public const SCHEMA_TARGET_VERSION = 2;

    /** Module preference key holding the webhook token (§6.1). */
    public const PREF_EVENT_TOKEN = 'event_token';

    // Single source of truth lives in ScheduleService (shared with the
    // external-job spec validator).
    public const TIMEOUT_MIN = ScheduleService::TIMEOUT_MIN;
    public const TIMEOUT_MAX = ScheduleService::TIMEOUT_MAX;
    public const TIMEOUT_STD = ScheduleService::TIMEOUT_STD;

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
    }

    // =========================================================================
    // MiddlewareInterface (page-load watchdog for the watch daemon)
    // =========================================================================

    /**
     * Runs on every request (webtrees auto-registers module middlewares via
     * Router.php). Respawn the resident watch daemon when the admin has enabled
     * it and it died. Cheap (one file_exists) when the watch is off; never
     * throws and never blocks the request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        WatchService::maybeSpawn();

        return $handler->handle($request);
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

        // Ensure a webhook token exists (generated once, lazily).
        if ($this->getPreference(self::PREF_EVENT_TOKEN) === '') {
            $this->setPreference(self::PREF_EVENT_TOKEN, self::generateEventToken());
        }

        // Jobs currently offered by other modules, keyed as <module>:<name>.
        // The admin view uses this for the provenance badge and the
        // "reset to module defaults" button. discoverExternalJobs() is
        // exception-safe (malformed sources are skipped).
        $offered = [];
        foreach (ScheduleService::discoverExternalJobs() as $entry) {
            $short   = trim((string) $entry['module'], '_');
            $offered[$short . ':' . (string) $entry['spec']['name']] = $short;
        }

        return $this->viewResponse($this->name() . '::admin', [
            'title'       => $this->title(),
            'module'      => $this,
            'jobs'        => CronjobUtils::listJobs(),
            'offline'     => CliBootstrap::siteIsOffline(),
            'cron_lib'    => ScheduleService::hasCronLibrary(),
            'cron_now'    => ScheduleService::now(),
            'install'     => CronjobUtils::triggerInstallBlocks(),
            'watch'       => WatchService::status(),
            'event_token' => $this->getPreference(self::PREF_EVENT_TOKEN),
            'offered'     => $offered,
        ]);
    }

    /**
     * Job create/edit form (?job=<id> to edit, ?duplicate=<id> to open the
     * form prefilled with a copy of that job: new -copy slug, disabled).
     */
    public function getAdminJobFormAction(ServerRequestInterface $request): ResponseInterface {
        $this->layout = 'layouts/administration';

        $job_id    = Validator::queryParams($request)->integer('job', 0);
        $duplicate = Validator::queryParams($request)->integer('duplicate', 0);
        $job       = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;
        $copy_of   = null;

        if ($job === null && $duplicate > 0) {
            $source = CronjobUtils::findJob($duplicate);
            if ($source !== null) {
                $job          = clone $source;
                $job->id      = 0;
                $job->enabled = 0;
                // Module-offered jobs are keyed <module>:<name>; the colon
                // cannot be saved via the form, so the copy's base name
                // strips the prefix.
                $job->name    = CronjobUtils::uniqueCopySlug(CronjobUtils::copySlugBase((string) $source->name));
                $copy_of      = (string) $source->title;
            }
        }

        $trigger_type = $job !== null ? ((string) $job->trigger_type === 'event' ? 'event' : 'time') : 'time';
        $event_name   = $job !== null ? (string) ($job->event_name ?? '') : '';
        $notify       = $job !== null ? ((int) $job->notify === 1) : false;

        return $this->jobFormView($job, $copy_of, $trigger_type, $event_name, $notify, null);
    }

    /**
     * Render the job create/edit form. $form_values (submitted field values)
     * are restored when a save failed validation, so no input is lost.
     *
     * @param array<string, mixed>|null $form_values
     */
    private function jobFormView(?object $job, ?string $copy_of, string $trigger_type, string $event_name, bool $notify, ?array $form_values): ResponseInterface {
        $preview = null;
        $cron    = (string) ($form_values['cron'] ?? ($job !== null ? $job->cron : ''));
        if ($trigger_type === 'time' && $cron !== '' && ScheduleService::hasCronLibrary()) {
            try {
                $preview = ScheduleService::upcomingRuns($cron, 5, ScheduleService::now());
            } catch (DomainException | RuntimeException) {
                $preview = null;
            }
        }

        return $this->viewResponse($this->name() . '::job-form', [
            'title'        => I18N::translate('Cron Job Scheduler'),
            'module'       => $this,
            'job'          => $job,
            'copy_of'      => $copy_of,
            'form_values'  => $form_values,
            'candidates'   => array_merge(CronjobUtils::jobScriptCandidates(), JobRunner::ALLOWED_CORE_COMMANDS),
            'preview'      => $preview,
            'cron_now'     => ScheduleService::now(),
            'trigger_type' => $trigger_type,
            'event_name'   => $event_name,
            'notify'       => $notify,
        ]);
    }

    /**
     * Save a job (create or update).
     */
    public function postAdminJobSaveAction(ServerRequestInterface $request): ResponseInterface {
        $data         = Validator::parsedBody($request);
        $name         = trim($data->string('name', ''));
        $title        = trim($data->string('title', ''));
        $cron         = trim($data->string('cron', ''));
        $command      = trim($data->string('command', ''));
        $args         = trim($data->string('args', ''));
        $timeout      = $data->integer('timeout', self::TIMEOUT_STD);
        $enabled      = $data->boolean('enabled', false);
        $notify       = $data->boolean('notify', false);
        $trigger_type = $data->string('trigger_type', 'time') === 'event' ? 'event' : 'time';
        $event_name   = trim($data->string('event_name', ''));
        $job_id       = $data->integer('job_id', 0);
        $copy_of      = $data->string('copy_of', '') !== '' ? $data->string('copy_of') : null;

        $existing = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        // Submitted values, restored when the save fails (form re-render).
        $form_values = [
            'name'    => $name,
            'title'   => $title,
            'cron'    => $cron,
            'command' => $command,
            'args'    => $args,
            'timeout' => $timeout,
            'enabled' => $enabled,
        ];

        $errors = [];
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $name) !== 1) {
            $errors[] = I18N::translate('Job name must be a slug: %1$s, max %2$s characters.', 'a-z, 0-9, "_", "-"', '64');
        }
        if ($title === '') {
            $errors[] = I18N::translate('Job title must not be empty.');
        }
        if ($trigger_type === 'event') {
            if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $event_name) !== 1) {
                $errors[] = I18N::translate('Event name must be a slug: %1$s, max %2$s characters.', 'a-z, 0-9, "_", "-"', '64');
            }
        } else {
            try {
                ScheduleService::validateCron($cron);
            } catch (DomainException | RuntimeException $exception) {
                $errors[] = I18N::translate('Invalid cron expression "%1$s": %2$s', $cron, $exception->getMessage());
            }
            if (strlen($cron) > 64) {
                $errors[] = I18N::translate('Cron expression must be at most %d characters.', 64);
            }
        }
        // DB column widths (Migration0/1): title 128, cron 64, args 255.
        if (mb_strlen($title) > 128) {
            $errors[] = I18N::translate('Job title must be at most %d characters.', 128);
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
            // Re-render the form with the submitted values (no redirect, so
            // nothing the user entered is lost and the duplicate context
            // survives); the layout shows the flash messages.
            $this->layout = 'layouts/administration';

            return $this->jobFormView($existing, $copy_of, $trigger_type, $event_name, $notify, $form_values);
        }

        // Renaming onto another job's slug would overwrite that job.
        $foreign = DB::table('cj_job')->where('name', '=', $name)->first();
        if ($foreign !== null && ($existing === null || (int) $foreign->id !== (int) $existing->id)) {
            FlashMessages::addMessage(I18N::translate('A job with this name already exists.'), 'danger');
            $this->layout = 'layouts/administration';

            return $this->jobFormView($existing, $copy_of, $trigger_type, $event_name, $notify, $form_values);
        }

        $now = ScheduleService::now();
        CronjobUtils::saveJob([
            'name'         => $name,
            'title'        => $title,
            'trigger_type' => $trigger_type,
            'event_name'   => $trigger_type === 'event' ? $event_name : '',
            'cron'         => $trigger_type === 'time' ? $cron : '',
            'command_type' => $command_type,
            'command'      => $command,
            'args'         => $args,
            'timeout_sec'  => max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, $timeout)),
            'enabled'      => $enabled,
            'notify'       => $notify,
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
     * Reset a job to the defaults currently offered by its module
     * (manifest / getCronJobs): title, trigger, cron, command, args and
     * enabled are restored. The slug, the notify setting and the run
     * history are kept. Jobs without a current offer cannot be reset.
     */
    public function postAdminJobResetAction(ServerRequestInterface $request): ResponseInterface {
        $job_id = Validator::parsedBody($request)->integer('job_id', 0);
        $job    = $job_id > 0 ? CronjobUtils::findJob($job_id) : null;

        if ($job === null) {
            FlashMessages::addMessage(I18N::translate('Job not found.'), 'danger');

            return redirect($this->getConfigLink());
        }

        $spec = ScheduleService::findOfferedSpec((string) $job->name);
        if ($spec === null) {
            FlashMessages::addMessage(I18N::translate('This job is not (any longer) offered by a module - reset is not possible.'), 'danger');

            return redirect($this->getConfigLink());
        }

        CronjobUtils::saveJob([
            'name'         => (string) $job->name,
            'title'        => (string) $spec['title'],
            'trigger_type' => (string) $spec['trigger_type'],
            'event_name'   => (string) ($spec['event_name'] ?? ''),
            'cron'         => (string) $spec['cron'],
            'command_type' => (string) $spec['command_type'],
            'command'      => (string) $spec['command'],
            'args'         => (string) $spec['args'],
            'timeout_sec'  => (int) $spec['timeout_sec'],
            'enabled'      => (bool) $spec['enabled'],
            'notify'       => (int) $job->notify === 1,
            'created_at'   => (string) $job->created_at,
        ], ScheduleService::now(), (int) $job->id);

        FlashMessages::addMessage(I18N::translate('Job reset to the module defaults.'), 'success');

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

    // =========================================================================
    // Webhook: event trigger (§6.1). NOT admin-only (the name has no "admin"),
    // so ModuleAction allows non-logged-in callers; the shared token + a
    // site-wide rate limit are the access controls instead.
    //
    // It is a GET (not a POST): webtrees' CheckCsrf middleware rejects every
    // POST without a session CSRF token, which an external system cannot send.
    // The token is the access control and should be sent as the
    // X-Cronjob-Token header (not the query string) so it is not written to
    // access logs.
    // =========================================================================

    /**
     * GET /module/_cronjob_/Event?event=<name>&data=<json>
     * (token in the X-Cronjob-Token header, or as ?token=<token>).
     *
     * Queues an event for the tick to dispatch to matching event-triggered
     * jobs. Fails closed: a missing/empty token is always rejected.
     */
    public function getEventAction(ServerRequestInterface $request): ResponseInterface {
        $factory = Registry::responseFactory();

        if (DB::table('module')->where('module_name', '=', $this->name())->value('status') !== 'enabled') {
            return $factory->response(['ok' => false, 'error' => 'module disabled'], 503);
        }

        // Throttle first (before any per-request work), to blunt token guessing.
        /** @var RateLimitService $rate_limit */
        $rate_limit = Registry::container()->get(RateLimitService::class);
        $rate_limit->limitRateForSite(20, 60, 'cronjob_event_limit');

        $expected = $this->getPreference(self::PREF_EVENT_TOKEN);
        $provided = $request->getHeaderLine('X-Cronjob-Token');
        if ($provided === '') {
            $provided = Validator::queryParams($request)->string('token', '');
        }
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return $factory->response(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $event = trim(Validator::queryParams($request)->string('event', ''));
        if (preg_match(EventQueue::NAME_PATTERN, $event) !== 1) {
            return $factory->response(['ok' => false, 'error' => 'invalid event'], 400);
        }

        $data_raw = trim(Validator::queryParams($request)->string('data', ''));
        $data     = [];
        if ($data_raw !== '') {
            $decoded = json_decode($data_raw, true);
            if (!is_array($decoded)) {
                return $factory->response(['ok' => false, 'error' => 'data must be a JSON object/array'], 400);
            }
            $data = $decoded;
        }

        EventQueue::push($event, $data);

        return $factory->response(['ok' => true, 'queued' => 1], 200);
    }

    /**
     * Regenerate the webhook token (admin).
     */
    public function postAdminEventTokenAction(ServerRequestInterface $request): ResponseInterface {
        $this->setPreference(self::PREF_EVENT_TOKEN, self::generateEventToken());
        FlashMessages::addMessage(I18N::translate('Webhook token regenerated - update any external sender.'), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Generate a fresh webhook token (32 hex characters).
     */
    public static function generateEventToken(): string {
        return bin2hex(random_bytes(16));
    }
}
