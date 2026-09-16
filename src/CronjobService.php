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
use PDOException;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\Services\DataFiles;
use Schwendinger\Webtrees\Module\Cronjob\Services\EventCatalogService;
use Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue;
use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\PseudoEventService;
use Schwendinger\Webtrees\Module\Cronjob\Services\RouteEventService;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;
use Schwendinger\Webtrees\Services\CliBootstrap;

use function array_map;
use function filemtime;
use function is_file;
use function ksort;
use function max;
use function time;

/**
 * Public, stable query API for OTHER modules (the "cronjob service API").
 *
 * A neighboring module checks once with class_exists (the module may not be
 * installed - its autoloader is registered by its module.php only when
 * present) and then talks to this facade instead of the internal Services:
 *
 *     use Schwendinger\Webtrees\Module\Cronjob\CronjobService;
 *
 *     if (class_exists(CronjobService::class) && CronjobService::isFunctional()) {
 *         $status = CronjobService::jobStatus('mymodule:backup');
 *         if ($status !== null && $status['enabled'] && $status['last_status'] === 'error') {
 *             // react...
 *         }
 *     }
 *
 * Contract:
 *  - read-only methods never throw for a not-yet-migrated module - they
 *    return safe defaults (false / null / []);
 *  - actions throw or return false when the schema is missing;
 *  - the database is assumed connected (webtrees web context or a booted
 *    CLI context, like everywhere in this module);
 *  - timestamps are UTC 'Y-m-d H:i:s' (or Unix for lastTick*); descriptions
 *    are source strings (translation keys), never pre-translated;
 *  - lastRun() deliberately excludes the run OUTPUT, which may contain
 *    personal data or site settings (see cli/tick.php header).
 *
 * Read-only apart from runJobNow() / pushEvent() - the explicit action set.
 */
final class CronjobService {

    /** Seconds without a tick marker after which the tick machinery counts as stale. */
    public const TICK_STALE_SECONDS = 300;

    private const TICK_LAST_FILE = 'tick.last';

    // --- Availability ------------------------------------------------------

    /**
     * Is the module enabled (webtrees module status)?
     */
    public static function isEnabled(): bool {
        return DB::table('module')
            ->where('module_name', '=', CronjobUtils::MODULE_NAME)
            ->value('status') === 'enabled';
    }

    /**
     * Is the module's schema migrated (cj_* tables present)?
     */
    public static function isMigrated(): bool {
        try {
            return DB::schema()->hasTable('cj_job');
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Basic functionality: enabled, migrated, and the bundled cron
     * expression library is present (time triggers need it).
     */
    public static function isFunctional(): bool {
        return self::isEnabled() && self::isMigrated() && ScheduleService::hasCronLibrary();
    }

    /**
     * Concrete problems, empty list = healthy.
     *
     * @return list<string> 'module_disabled' | 'not_migrated' | 'cron_library_missing' | 'tick_stale'
     */
    public static function problems(): array {
        $problems = [];
        if (!self::isEnabled()) {
            $problems[] = 'module_disabled';
        }
        if (!self::isMigrated()) {
            $problems[] = 'not_migrated';
        }
        if (!ScheduleService::hasCronLibrary()) {
            $problems[] = 'cron_library_missing';
        }
        // tick_stale only when the tick ran before and then stopped - and the
        // site is not offline (then idling is expected, not a problem).
        $age = self::lastTickAge();
        if ($age !== null && $age > self::TICK_STALE_SECONDS && !CliBootstrap::siteIsOffline()) {
            $problems[] = 'tick_stale';
        }

        return $problems;
    }

    // --- Features ------------------------------------------------------------

    /**
     * The feature flags:
     *  - watch:         the resident watch daemon is opted in
     *  - pseudo_events: the cronjob:pseudo-events job (log pollers) is enabled
     *  - route_events:  route-triggered events (module setting)
     *
     * @return array{watch: bool, pseudo_events: bool, route_events: bool}
     */
    public static function features(): array {
        return [
            'watch'         => WatchService::featureEnabled(),
            'pseudo_events' => self::isJobEnabled('cronjob:pseudo-events'),
            'route_events'  => RouteEventService::isEnabled(),
        ];
    }

    /**
     * The watch daemon state (enabled, running, last heartbeat, PID).
     *
     * @return array{enabled: bool, running: bool, last_tick: int, pid: int|null}
     */
    public static function watchStatus(): array {
        return WatchService::status();
    }

    // --- Tick ------------------------------------------------------------------

    /**
     * Unix timestamp of the last tick execution (watch daemon or OS trigger;
     * manual --job runs count too) or null when none ever ran.
     */
    public static function lastTick(): ?int {
        $file = DataFiles::path(self::TICK_LAST_FILE);

        return is_file($file) ? (int) filemtime($file) : null;
    }

    /**
     * Seconds since the last tick, or null when none ever ran.
     */
    public static function lastTickAge(): ?int {
        $last = self::lastTick();

        return $last === null ? null : max(0, time() - $last);
    }

    // --- Jobs (names are namespaced: <module>:<name>) ----------------------------

    /**
     * The names of all registered jobs.
     *
     * @return list<string>
     */
    public static function jobNames(): array {
        if (!self::isMigrated()) {
            return [];
        }

        return array_map(
            static fn (object $job): string => (string) $job->name,
            DB::table('cj_job')->orderBy('name')->get()->all()
        );
    }

    /**
     * Is the job known and enabled?
     */
    public static function isJobEnabled(string $name): bool {
        if (!self::isMigrated()) {
            return false;
        }

        return (int) DB::table('cj_job')->where('name', '=', $name)->value('enabled') === 1;
    }

    /**
     * The status of one job, or null when unknown.
     *
     * @return array{name: string, title: string, enabled: bool, command: string,
     *               last_run_at: string|null, last_exit: int|null, last_status: string|null,
     *               next_run_at: string|null,
     *               triggers: list<array{type: string, cron: string, event: string}>}|null
     */
    public static function jobStatus(string $name): ?array {
        if (!self::isMigrated()) {
            return null;
        }
        $job = ScheduleService::findJob($name);
        if ($job === null) {
            return null;
        }

        return [
            'name'        => (string) $job->name,
            'title'       => (string) $job->title,
            'enabled'     => (int) $job->enabled === 1,
            'command'     => (string) $job->command,
            'last_run_at' => $job->last_run_at !== null ? (string) $job->last_run_at : null,
            'last_exit'   => $job->last_exit !== null ? (int) $job->last_exit : null,
            'last_status' => $job->last_status !== null ? (string) $job->last_status : null,
            'next_run_at' => $job->next_run_at !== null ? (string) $job->next_run_at : null,
            'triggers'    => ScheduleService::jobTriggers((int) $job->id),
        ];
    }

    /**
     * The most recent run of a job, or null when it never ran.
     * Deliberately WITHOUT the output column (see class docblock).
     *
     * @return array{id: int, trigger: string, trigger_detail: string|null,
     *               started_at: string, finished_at: string|null,
     *               exit_code: int|null, status: string, duration_ms: int|null}|null
     */
    public static function lastRun(string $name): ?array {
        if (!self::isMigrated()) {
            return null;
        }
        $job = ScheduleService::findJob($name);
        if ($job === null) {
            return null;
        }
        $run = DB::table('cj_run')
            ->where('job_id', '=', (int) $job->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
        if ($run === null) {
            return null;
        }

        return [
            'id'             => (int) $run->id,
            'trigger'        => (string) $run->trigger,
            'trigger_detail' => $run->trigger_detail !== null ? (string) $run->trigger_detail : null,
            'started_at'     => (string) $run->started_at,
            'finished_at'    => $run->finished_at !== null ? (string) $run->finished_at : null,
            'exit_code'      => $run->exit_code !== null ? (int) $run->exit_code : null,
            'status'         => (string) $run->status,
            'duration_ms'    => $run->duration_ms !== null ? (int) $run->duration_ms : null,
        ];
    }

    // --- Events --------------------------------------------------------------------

    /**
     * The events at least one ENABLED event job listens for:
     * event name => the listening job names.
     *
     * @return array<string, list<string>>
     */
    public static function listenedEvents(): array {
        if (!self::isMigrated()) {
            return [];
        }
        $map  = [];
        $rows = DB::table('cj_job_trigger')
            ->join('cj_job', 'cj_job.id', '=', 'cj_job_trigger.job_id')
            ->where('cj_job.enabled', '=', 1)
            ->where('cj_job_trigger.trigger_type', '=', ScheduleService::TRIGGER_EVENT)
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

    /**
     * The names of the enabled jobs listening for one event ([] = none).
     *
     * @return list<string>
     */
    public static function listenersFor(string $event_name): array {
        if (!self::isMigrated()) {
            return [];
        }

        return array_map(
            static fn (object $job): string => (string) $job->name,
            ScheduleService::eventJobs($event_name)
        );
    }

    /**
     * The event inventory (module-announced, built-in pseudo-events, route
     * events, currently-listened names) - descriptions are source strings.
     *
     * @return array<string, array{source: string, description: string, payload: list<string>}>
     */
    public static function eventCatalog(): array {
        if (!self::isMigrated()) {
            return [];
        }

        return EventCatalogService::listCatalog();
    }

    /**
     * The built-in pseudo-event detectors (feature catalog, DB-free).
     *
     * @return array<string, string> event name => description (source string)
     */
    public static function pseudoEvents(): array {
        $out = [];
        foreach (PseudoEventService::detectors() as $detector) {
            $out[$detector->eventName()] = $detector->description();
        }

        return $out;
    }

    /**
     * The route-triggered events (feature catalog, DB-free; first call per
     * process builds the route table, then cached).
     *
     * @return array<string, string> event name => route path
     */
    public static function routeEvents(): array {
        $out = [];
        foreach (RouteEventService::map() as $entry) {
            $out[(string) $entry['event']] = (string) $entry['path'];
        }
        ksort($out);

        return $out;
    }

    // --- Actions (small, explicit) ----------------------------------------------------

    /**
     * Queue a job for the next tick (same semantics as the admin "Run now"):
     * next_run_at is set to now. Returns false for unknown names or a
     * not-migrated module.
     */
    public static function runJobNow(string $name): bool {
        if (!self::isMigrated()) {
            return false;
        }
        $job = ScheduleService::findJob($name);
        if ($job === null) {
            return false;
        }

        $now = ScheduleService::now();
        DB::table('cj_job')->where('id', '=', (int) $job->id)->update([
            'next_run_at' => $now,
            'updated_at'  => $now,
        ]);

        return true;
    }

    /**
     * Queue an event for the tick's event drain (validates the name).
     *
     * @throws RuntimeException when the module is not migrated
     * @throws \InvalidArgumentException for an invalid event name (EventQueue::push)
     */
    public static function pushEvent(string $event_name, array $payload = []): int {
        if (!self::isMigrated()) {
            throw new RuntimeException('cronjob module not migrated - cannot queue events');
        }

        return EventQueue::push($event_name, $payload);
    }
}
