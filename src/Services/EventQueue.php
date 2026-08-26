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
use InvalidArgumentException;

use function date;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_match;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The phase-2 event queue (cj_event).
 *
 * webtrees has no event system, so events are plain DB rows that the tick
 * drains once a minute. Producers are (1) the token-protected webhook and
 * (2) direct push() calls from other modules' code. A job with
 * trigger_type='event' runs once for each queued event whose name matches its
 * event_name. Each event is consumed (marked handled) after its matching jobs
 * have been attempted - once, even if the run fails (no infinite retry).
 *
 * NOTE for other modules: calling this class requires the cronjob module to be
 * installed (it lives in the cronjob namespace). Guard the call with
 * class_exists(...) in offering modules.
 */
final class EventQueue {

    /** Event names are slugs: [a-z0-9][a-z0-9_-]{0,63}. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/';

    private const PAYLOAD_MAX = 4000;

    private const PURGE_AFTER_DAYS = 7;

    /**
     * Queue an event. Returns the new event id.
     *
     * @param array<string, mixed> $payload arbitrary, JSON-encodable
     *
     * @throws InvalidArgumentException
     */
    public static function push(string $event_name, array $payload = []): int {
        $event_name = trim($event_name);
        if (preg_match(self::NAME_PATTERN, $event_name) !== 1) {
            throw new InvalidArgumentException('invalid event name: ' . $event_name);
        }

        $json = '';
        if ($payload !== []) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::PAYLOAD_MAX) {
                $json = substr($json, 0, self::PAYLOAD_MAX);
            }
        }

        return (int) DB::table('cj_event')->insertGetId([
            'event_name' => $event_name,
            'payload'    => $json,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Pending (not yet consumed) events, oldest first.
     *
     * @return list<object>
     */
    public static function pending(?string $event_name = null, int $limit = 100): array {
        $query = DB::table('cj_event')->whereNull('handled_run_id')->orderBy('id')->limit($limit);
        if ($event_name !== null) {
            $query->where('event_name', '=', $event_name);
        }

        return $query->get()->all();
    }

    /**
     * Mark an event consumed by a run (retention + audit; see purge()).
     */
    public static function markHandled(int $event_id, int $run_id): void {
        DB::table('cj_event')->where('id', '=', $event_id)->update([
            'handled_run_id' => $run_id,
        ]);
    }

    /**
     * Drop handled events older than the retention window. (Pending events are
     * kept - a stopped tick must not lose queued work.)
     */
    public static function purge(string $now): void {
        $threshold = date('Y-m-d H:i:s', strtotime($now) - self::PURGE_AFTER_DAYS * 86400);

        DB::table('cj_event')
            ->whereNotNull('handled_run_id')
            ->where('created_at', '<', $threshold)
            ->delete();
    }

    /**
     * Decode an event's JSON payload (defensive: malformed JSON -> []).
     *
     * @return array<string, mixed>
     */
    public static function decodePayload(object $event): array {
        $raw = (string) ($event->payload ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
