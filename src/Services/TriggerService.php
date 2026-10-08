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

use DomainException;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;

use function array_values;
use function is_array;
use function json_encode;
use function strlen;
use function trim;
use function usort;

use const JSON_UNESCAPED_SLASHES;

/**
 * Trigger normalization, canonical keys and due-time computation
 * (§13 multi-trigger). Pure - standalone-testable without the webtrees
 * core or a database.
 */
final class TriggerService {

    /** Trigger types for cj_job_trigger.trigger_type (§13). */
    public const TRIGGER_TIME  = 'time';
    public const TRIGGER_EVENT = 'event';

    /**
     * Normalize + validate a job's trigger list (§13, multi-trigger).
     *
     * Accepts the new 'triggers' list - entries ['type' => 'time', 'cron' => …]
     * or ['type' => 'event', 'event' => …] - and, for backward compatibility
     * (pre-§13 manifests, stored specs, tests), the legacy single-trigger
     * keys 'trigger_type' + 'cron' / 'event_name'. Returns the canonical
     * list, deduplicated, sorted time-first.
     *
     * @param array<string, mixed> $spec
     *
     * @return array{triggers: list<array{type: string, cron: string, event: string}>, errors: list<string>}
     */
    public static function normalizeTriggers(array $spec): array {
        $errors = [];
        $raw    = [];

        if (isset($spec['triggers']) && is_array($spec['triggers'])) {
            foreach (array_values($spec['triggers']) as $raw_trigger) {
                if (is_array($raw_trigger)) {
                    $raw[] = $raw_trigger;
                }
            }
        }

        if ($raw === []) {
            // Legacy single-trigger shape.
            $legacy_type = (string) ($spec['trigger_type'] ?? self::TRIGGER_TIME);
            if ($legacy_type === self::TRIGGER_EVENT) {
                $raw[] = ['type' => self::TRIGGER_EVENT, 'event' => (string) ($spec['event_name'] ?? '')];
            } else {
                $raw[] = ['type' => self::TRIGGER_TIME, 'cron' => (string) ($spec['cron'] ?? '')];
            }
        }

        $triggers = [];
        $seen     = [];
        foreach ($raw as $raw_trigger) {
            $type = (string) ($raw_trigger['type'] ?? '');
            if ($type === self::TRIGGER_TIME) {
                $value = trim((string) ($raw_trigger['cron'] ?? ''));
                if ($value === '' || strlen($value) > 64) {
                    $errors[] = "time triggers require a cron expression of 1-64 chars (got '{$value}')";
                    continue;
                }
                try {
                    CronExpressionService::validateCron($value);
                } catch (DomainException | RuntimeException $exception) {
                    $errors[] = 'invalid cron expression: ' . $exception->getMessage();
                    continue;
                }
                $key = self::TRIGGER_TIME . ':' . $value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key]   = true;
                $triggers[]   = ['type' => self::TRIGGER_TIME, 'cron' => $value, 'event' => ''];
            } elseif ($type === self::TRIGGER_EVENT) {
                $value = trim((string) ($raw_trigger['event'] ?? ''));
                if (!JobNaming::isValidEventName($value)) {
                    $errors[] = "event triggers require an event name (slug or <domain>:slug, max 64 chars) - got '{$value}'";
                    continue;
                }
                $key = self::TRIGGER_EVENT . ':' . $value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key]   = true;
                $triggers[]   = ['type' => self::TRIGGER_EVENT, 'cron' => '', 'event' => $value];
            } else {
                $errors[] = "trigger type '{$type}' is not supported ('time' or 'event')";
            }
        }

        // Time triggers first, so the primary schedule reads first in the UI.
        usort($triggers, static fn (array $a, array $b): int => (($a['type'] === self::TRIGGER_EVENT) ? 1 : 0) - (($b['type'] === self::TRIGGER_EVENT) ? 1 : 0));

        if ($errors === [] && $triggers === []) {
            $errors[] = 'a job needs at least one trigger (a cron schedule or an event name)';
        }

        return ['triggers' => $triggers, 'errors' => $errors];
    }

    /**
     * Canonical, order-independent key of a trigger list (§13) - compares
     * stored vs offered triggers in JobDiscovery::specDiff().
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     */
    public static function triggersKey(array $triggers): string {
        $times  = [];
        $events = [];
        foreach ($triggers as $trigger) {
            if (($trigger['type'] ?? '') === self::TRIGGER_TIME) {
                $times[]  = (string) ($trigger['cron'] ?? '');
            } else {
                $events[] = (string) ($trigger['event'] ?? '');
            }
        }
        sort($times);
        sort($events);

        return json_encode(['time' => $times, 'event' => $events], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The minimum of the next runs over all time triggers (§13) - the
     * job-level cj_job.next_run_at. NULL when the job has no time trigger.
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     */
    public static function nextRunMin(array $triggers, string $from): ?string {
        $min = null;
        foreach ($triggers as $trigger) {
            if (($trigger['type'] ?? '') !== self::TRIGGER_TIME) {
                continue;
            }
            try {
                $next = CronExpressionService::nextRun((string) $trigger['cron'], $from);
            } catch (DomainException | RuntimeException) {
                continue;
            }
            if ($min === null || $next < $min) {
                $min = $next;
            }
        }

        return $min;
    }

    /**
     * The next run the self-heal assigns to a stranded job (next_run_at =
     * NULL) - or NULL when the job has no time trigger (pure event jobs
     * legitimately keep next_run_at = NULL) or no cron can be scheduled.
     * Pure - standalone-testable.
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     */
    public static function nextRunForRepair(array $triggers, string $from): ?string {
        foreach ($triggers as $trigger) {
            if (($trigger['type'] ?? '') === self::TRIGGER_TIME) {
                return self::nextRunMin($triggers, $from);
            }
        }

        return null;
    }

    /**
     * Which time triggers were due between $anchor and $now (§13): the crons
     * whose next run after $anchor is not after $now. Records
     * cj_run.trigger_detail for schedule runs. Pure and standalone-testable.
     *
     * @param list<array{type: string, cron: string, event: string}> $triggers
     *
     * @return list<string> the due cron expressions (sorted)
     */
    public static function dueTriggerDetails(array $triggers, string $anchor, string $now): array {
        $due = [];
        foreach ($triggers as $trigger) {
            if (($trigger['type'] ?? '') !== self::TRIGGER_TIME) {
                continue;
            }
            try {
                if (CronExpressionService::nextRun((string) $trigger['cron'], $anchor) <= $now) {
                    $due[] = (string) $trigger['cron'];
                }
            } catch (DomainException | RuntimeException) {
                continue;
            }
        }
        sort($due);

        return $due;
    }
}
