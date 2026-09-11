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

namespace Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents;

use Fisharebest\Webtrees\DB;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\DataFiles;
use Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
use Throwable;

use function array_flip;
use function array_intersect_key;
use function file_get_contents;
use function filemtime;
use function file_put_contents;
use function fopen;
use function flock;
use function fclose;
use function getmypid;
use function in_array;
use function is_file;
use function json_decode;
use function json_encode;
use function rename;
use function str_starts_with;
use function time;
use function touch;
use function unlink;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The polling pseudo-event runner.
 *
 * webtrees has no event bus, so core actions are observed by periodically
 * polling state and queueing an event when a detector sees a transition. The
 * built-in detectors (LogRowDetector) poll the core `log` table (app/Log.php)
 * and fire one event per new matching log row. The tick's existing
 * drainEvents() then runs the matching event-triggered jobs.
 *
 * Single trigger: the offered job `cronjob:pseudo-events`
 * (cli/pseudo-events.php), which runs in the tick's child process - isolated
 * from any web request and bounded by the job's timeout. A heavy detector can
 * therefore never block or slow down a page load.
 *
 * Gated by that job being enabled, by a listener for each event name (a
 * detector whose event no job listens for is not even queried), rate-limited
 * (MIN_INTERVAL - a cooldown that also caps a more frequent job cron) and
 * serialized (flock). State lives in data/cronjob/pseudo-events.json.
 */
final class PseudoEventService {

    /** Minimum seconds between two detection runs (idempotency guard / rate limit). */
    public const MIN_INTERVAL = 300;

    private const STATE_FILE = 'pseudo-events.json';
    private const LOCK_FILE  = 'pseudo-events.lock';
    private const LAST_FILE  = 'pseudo-events-last';

    /**
     * The registered detectors (the single place to add a new one). The
     * message prefixes are the hard-coded English strings of the current core
     * (LoginAction, Logout, GedcomRecord) - see LogRowDetector.
     *
     * @return list<PseudoEventDetectorInterface>
     */
    public static function detectors(): array {
        return [
            new LogRowDetector('cronjob:log-auth-failed', MoreI18N::translate('Failed login attempts (log type auth, message prefix "Login failed")'), 'auth', ['Login failed']),
            new LogRowDetector('cronjob:log-auth-login', MoreI18N::translate('Successful logins (log type auth, message prefix "Login: ")'), 'auth', ['Login: ']),
            new LogRowDetector('cronjob:log-auth-logout', MoreI18N::translate('Logouts (log type auth, message prefix "Logout: ")'), 'auth', ['Logout: ']),
            new LogRowDetector('cronjob:log-error', MoreI18N::translate('Application errors (log type error - the message is the exception trace)'), 'error'),
            new LogRowDetector('cronjob:log-edit-update', MoreI18N::translate('Record updates (log type edit, message prefix "Update: ")'), 'edit', ['Update: ']),
            new LogRowDetector('cronjob:log-edit-delete', MoreI18N::translate('Record deletions (log type edit, message prefix "Delete: ")'), 'edit', ['Delete: ']),
            new LogRowDetector('cronjob:log-search', MoreI18N::translate('Searches (log type search - one log row per searched tree)'), 'search'),
        ];
    }

    /**
     * Run the detectors once (guarded). Returns the number of events queued.
     * $force bypasses the cooldown (a manual CLI run).
     */
    public static function run(bool $force = false): int {
        if (CliBootstrap::siteIsOffline() || (!$force && !self::cooldownElapsed())) {
            return 0;
        }

        $lock = @fopen(self::lockPath(), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        try {
            // Re-check under the lock: another process may have just run.
            if (!$force && !self::cooldownElapsed()) {
                return 0;
            }
            $active = self::activeEventNames();
            if ($active === []) {
                @touch(self::lastPath());

                return 0;
            }

            return self::runDetectors($active);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Run every detector once, persist its new state, and queue its events.
     * Detectors whose event name no enabled job listens for are skipped
     * entirely - no query, no push (the consumer gate, per event name).
     *
     * @param list<string> $active distinct event names of enabled event jobs
     */
    private static function runDetectors(array $active): int {
        $state     = self::loadState();
        $detectors = $state['detectors'];
        $pushed    = 0;

        foreach (self::detectors() as $detector) {
            $name = $detector->eventName();
            if (!in_array($name, $active, true)) {
                continue;
            }
            try {
                $last    = $detectors[$name] ?? null;
                $current = $detector->currentState($last);
                $result  = $detector->compare($last, $current);
            } catch (Throwable) {
                continue; // a broken detector must not break the others
            }
            $detectors[$name] = $result['state'];

            foreach ((array) ($result['events'] ?? []) as $payload) {
                try {
                    EventQueue::push($name, (array) $payload);
                    $pushed++;
                } catch (Throwable) {
                    // Still persist the state so the row is not re-fired.
                }
            }
        }

        $detectors = self::pruneState($detectors);

        self::saveState([
            'last_run_at' => time(),
            'detectors'   => $detectors,
        ]);
        @touch(self::lastPath());

        return $pushed;
    }

    /**
     * The given event names that look like built-in pseudo-events (cronjob:*)
     * but have no detector any more - e.g. after a module update removed one.
     * Used by the admin table to flag orphaned job triggers (symmetric to
     * RouteEventService::orphanedEvents()). Pure apart from detectors().
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function orphanedEvents(array $names): array {
        $known = [];
        foreach (self::detectors() as $detector) {
            $known[$detector->eventName()] = true;
        }

        $orphaned = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($name !== '' && str_starts_with($name, 'cronjob:') && !isset($known[$name])) {
                $orphaned[] = $name;
            }
        }

        return $orphaned;
    }

    /**
     * Drop persisted state of detectors that no longer exist (e.g. after an
     * update removed one). Pure (standalone-testable).
     *
     * @param array<string, mixed> $detectors
     *
     * @return array<string, mixed>
     */
    public static function pruneState(array $detectors): array {
        $names = [];
        foreach (self::detectors() as $detector) {
            $names[] = $detector->eventName();
        }

        return array_intersect_key($detectors, array_flip($names));
    }

    /**
     * Has the detection cooldown (from the last run/consideration) elapsed?
     */
    public static function cooldownElapsed(): bool {
        $file = self::lastPath();

        return !is_file($file) || (time() - (int) filemtime($file)) >= self::MIN_INTERVAL;
    }

    /**
     * Distinct event names of enabled event-triggered jobs. Empty means no
     * event job is listening, so there is no point polling.
     *
     * @return list<string>
     */
    private static function activeEventNames(): array {
        return DB::table('cj_job_trigger')
            ->join('cj_job', 'cj_job.id', '=', 'cj_job_trigger.job_id')
            ->where('cj_job.enabled', 1)
            ->where('cj_job_trigger.trigger_type', '=', ScheduleService::TRIGGER_EVENT)
            ->whereNotNull('cj_job_trigger.event_name')
            ->distinct()
            ->pluck('cj_job_trigger.event_name')
            ->all();
    }

    /**
     * @return array{last_run_at: int, detectors: array<string, mixed>}
     */
    public static function loadState(): array {
        $file = self::statePath();
        if (!is_file($file)) {
            return ['last_run_at' => 0, 'detectors' => []];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['last_run_at' => 0, 'detectors' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['last_run_at' => 0, 'detectors' => []];
        }

        return [
            'last_run_at' => (int) ($decoded['last_run_at'] ?? 0),
            'detectors'   => is_array($decoded['detectors'] ?? null) ? $decoded['detectors'] : [],
        ];
    }

    /**
     * Atomically persist the detector state (tmp file + rename).
     *
     * @param array{last_run_at: int, detectors: array<string, mixed>} $state
     */
    public static function saveState(array $state): void {
        $file = self::statePath();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    private static function statePath(): string {
        return DataFiles::path(self::STATE_FILE);
    }

    private static function lockPath(): string {
        return DataFiles::path(self::LOCK_FILE);
    }

    private static function lastPath(): string {
        return DataFiles::path(self::LAST_FILE);
    }
}
