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
use PDOException;
use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\PseudoEventService;

use function array_map;
use function array_values;
use function is_array;
use function json_decode;
use function json_encode;
use function strlen;
use function strval;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The event inventory (§11): all event names that exist on this site.
 *
 * Sources (merged, first source wins for description/payload):
 *  1. events announced by modules (manifest 'events' section or a
 *     getModuleEvents() marker method), namespaced <module>:<name>
 *  2. built-in pseudo-event detectors (cronjob:*)
 *  3. route-triggered events (_route:*, derived from the live route table)
 *  4. event names currently listened for by an enabled event job
 *     (covers webhook-only names; source 'listening')
 *
 * The result is synced to the cj_event_catalog table by the tick (like the
 * job registry), so the admin UI and the job form datalist read a small
 * table instead of re-collecting. Descriptions are stored as source strings
 * (translation keys) and translated only at render time - the tick always
 * runs with I18N in en-US, so they must never be translated at sync time.
 */
final class EventCatalogService {

    /**
     * Collect all known events (live, no DB sync).
     *
     * @return array<string, array{source: string, description: string, payload: list<string>}>
     *         keyed by event name
     */
    public static function collect(): array {
        $catalog = [];

        // 1. Announced by modules.
        foreach (ScheduleService::discoverExternalEvents() as $entry) {
            $module = strval($entry['module']);
            $name   = $module . ':' . strval($entry['name']);
            if (strlen($name) > 64) {
                continue;
            }
            if (!isset($catalog[$name])) {
                $catalog[$name] = [
                    'source'      => $module,
                    'description' => strval($entry['description']),
                    'payload'     => array_values(array_map('strval', $entry['payload'])),
                ];
            }
        }

        // 2. Built-in pseudo-event detectors (already cronjob:-namespaced).
        foreach (PseudoEventService::detectors() as $detector) {
            $name = $detector->eventName();
            if (strlen($name) > 64 || isset($catalog[$name])) {
                continue;
            }
            $catalog[$name] = [
                'source'      => 'cronjob',
                'description' => $detector->description(),
                'payload'     => $detector->payloadKeys(),
            ];
        }

        // 3. Route-triggered events (one entry per event; paths may repeat).
        foreach (RouteEventService::map() as $entry) {
            $name = strval($entry['event']);
            if (strlen($name) > 64 || isset($catalog[$name])) {
                continue;
            }
            $catalog[$name] = [
                'source'      => RouteEventService::DOMAIN,
                'description' => 'POST ' . strval($entry['path']),
                'payload'     => RouteEventService::payloadKeys(strval($entry['path'])),
            ];
        }

        // 4. Listened for by an enabled event job (webhook-only names too).
        foreach (DB::table('cj_job_trigger')
            ->join('cj_job', 'cj_job.id', '=', 'cj_job_trigger.job_id')
            ->where('cj_job.enabled', '=', 1)
            ->where('cj_job_trigger.trigger_type', '=', ScheduleService::TRIGGER_EVENT)
            ->whereNotNull('cj_job_trigger.event_name')
            ->pluck('cj_job_trigger.event_name') as $name) {
            $name = strval($name);
            if ($name !== '' && strlen($name) <= 64 && !isset($catalog[$name])) {
                $catalog[$name] = [
                    'source'      => 'listening',
                    'description' => '',
                    'payload'     => [],
                ];
            }
        }

        return $catalog;
    }

    /**
     * Sync the collected catalog into cj_event_catalog (upsert + delete
     * vanished). Called by the tick - cheap, offline-safe, exception-safe at
     * the call site (a catalog problem must never break the tick).
     */
    public static function syncCatalog(string $now): void {
        $rows = self::collect();

        $seen = [];
        foreach ($rows as $name => $entry) {
            $seen[] = $name;
            DB::table('cj_event_catalog')->updateOrInsert(['event_name' => $name], [
                'source'      => $entry['source'],
                'description' => $entry['description'] !== '' ? $entry['description'] : null,
                'payload'     => $entry['payload'] !== []
                    ? json_encode($entry['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'updated_at'  => $now,
            ]);
        }

        if ($seen !== []) {
            DB::table('cj_event_catalog')->whereNotIn('event_name', $seen)->delete();
        }
    }

    /**
     * The catalog for display: the synced table, falling back to the live
     * collect() while the table is empty (before the first tick sync).
     *
     * @return array<string, array{source: string, description: string, payload: list<string>}>
     */
    public static function listCatalog(): array {
        try {
            $rows = DB::table('cj_event_catalog')->orderBy('event_name')->get()->all();
        } catch (PDOException) {
            return self::collect();
        }

        if ($rows === []) {
            return self::collect();
        }

        $out = [];
        foreach ($rows as $row) {
            $payload = [];
            $raw     = strval($row->payload ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = array_values(array_map('strval', array_values($decoded)));
                }
            }
            $out[strval($row->event_name)] = [
                'source'      => strval($row->source ?? ''),
                'description' => strval($row->description ?? ''),
                'payload'     => $payload,
            ];
        }

        return $out;
    }
}
