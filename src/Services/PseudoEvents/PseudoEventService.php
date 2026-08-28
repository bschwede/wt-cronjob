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
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
use Throwable;

use function dirname;
use function file_get_contents;
use function filemtime;
use function file_put_contents;
use function fopen;
use function flock;
use function fclose;
use function getmypid;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function time;
use function touch;
use function unlink;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The polling pseudo-event runner (phase 2, §6.1 Stufe B).
 *
 * webtrees has no event bus, so core actions (a GEDCOM import, new media, a
 * new user) are observed by periodically polling state and queueing an event
 * when a detector sees a transition. The tick's existing drainEvents() then
 * runs the matching event-triggered jobs.
 *
 * Single trigger: the offered job `cronjob:pseudo-events`
 * (cli/pseudo-events.php), which runs in the tick's child process - isolated
 * from any web request and bounded by the job's timeout. A heavy detector can
 * therefore never block or slow down a page load.
 *
 * Gated by that job being enabled (and by at least one enabled event-triggered
 * job listening), rate-limited (MIN_INTERVAL - a cooldown that also caps a more
 * frequent job cron) and serialized (flock). State lives in
 * data/cronjob-pseudo-events.json.
 */
final class PseudoEventService {

    /** Minimum seconds between two detection runs (idempotency guard / rate limit). */
    public const MIN_INTERVAL = 300;

    private const STATE_FILE = 'cronjob-pseudo-events.json';
    private const LOCK_FILE  = 'cronjob-pseudo-events.lock';
    private const LAST_FILE  = 'cronjob-pseudo-events-last';

    /**
     * The registered detectors (the single place to add a new one).
     *
     * @return list<PseudoEventDetectorInterface>
     */
    public static function detectors(): array {
        return [
            new GedcomFileDetector(),
            new MediaFileCountDetector(),
            new UserMaxIdDetector(),
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
            if (self::activeEventNames() === []) {
                @touch(self::lastPath());

                return 0;
            }

            return self::runDetectors();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Run every detector once, persist its new state, and queue an event for
     * each detected transition.
     */
    private static function runDetectors(): int {
        $state     = self::loadState();
        $detectors = $state['detectors'];
        $pushed    = 0;

        foreach (self::detectors() as $detector) {
            $name = $detector->eventName();
            try {
                $current = $detector->currentState();
                $result  = $detector->compare($detectors[$name] ?? null, $current);
            } catch (Throwable) {
                continue; // a broken detector must not break the others
            }
            $detectors[$name] = $result['state'];

            if (!empty($result['detected'])) {
                try {
                    EventQueue::push($name, (array) ($result['payload'] ?? []));
                    $pushed++;
                } catch (Throwable) {
                    // Still persist the state so the change is not re-fired.
                }
            }
        }

        self::saveState([
            'last_run_at' => time(),
            'detectors'   => $detectors,
        ]);
        @touch(self::lastPath());

        return $pushed;
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
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
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
        return Webtrees::DATA_DIR . self::STATE_FILE;
    }

    private static function lockPath(): string {
        return Webtrees::DATA_DIR . self::LOCK_FILE;
    }

    private static function lastPath(): string {
        return Webtrees::DATA_DIR . self::LAST_FILE;
    }
}
