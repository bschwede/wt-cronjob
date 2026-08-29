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

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * A log-table pseudo-event detector.
 *
 * webtrees writes an audit trail into the core `log` table (app/Log.php) for
 * sign-ins, edits, errors and searches. This detector polls that table
 * incrementally (checkpoint = highest seen log_id, persisted by the
 * PseudoEventService) and fires ONE event per new log row matching its
 * (log_type, message prefix) filter.
 *
 * Event semantics (see the module README, "Pseudo-events"):
 *  - the payload is the row itself: log_id, log_time, log_message (truncated
 *    to MAX_MESSAGE_LEN) and gedcom_id / user_id when they are set;
 *  - at most MAX_ROWS_PER_RUN rows per run (backpressure: the checkpoint
 *    advances only as far as the last fired row, the rest waits for the next
 *    run - no data loss, only latency);
 *  - the checkpoint is reset (baseline, no events) when the table shrank
 *    below it - TRUNCATE or a DB restore; plain row deletions cannot do this,
 *    because MySQL auto-increment continues after deletes.
 *
 * The message prefixes are the hard-coded English strings of the current core
 * (LoginAction, Logout, GedcomRecord). A core update that changes them silently
 * stops the matching events - documented, no fallback by design.
 */
final class LogRowDetector implements PseudoEventDetectorInterface {

    /** The payload keys every log-row event carries (event catalog). */
    private const PAYLOAD_KEYS = ['log_id', 'log_time', 'log_message', 'gedcom_id', 'user_id'];

    /** Maximum log rows fired per run (backpressure cap, see compare()). */
    private const MAX_ROWS_PER_RUN = 200;

    /** Incremental fetch window (rows since the checkpoint, this type only). */
    private const FETCH_LIMIT = 500;

    /** log_message truncation length in the event payload. */
    private const MAX_MESSAGE_LEN = 250;

    /**
     * @param list<string> $prefixes log_message prefixes that select a row
     *                                (empty = every row of the type)
     */
    public function __construct(
        private readonly string $event_name,
        private readonly string $description,
        private readonly string $log_type,
        private readonly array $prefixes = [],
    ) {
    }

    public function eventName(): string {
        return $this->event_name;
    }

    public function description(): string {
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function payloadKeys(): array {
        return self::PAYLOAD_KEYS;
    }

    /**
     * The log rows this detector has not seen yet (since its checkpoint),
     * plus the table's highest log_id (the reset probe for compare()).
     *
     * @param mixed $last_state the persisted checkpoint (a log_id), or null on the very first run
     *
     * @return array{rows: list<array{log_id: int, log_time: string, log_message: string, user_id: ?int, gedcom_id: ?int}>, max_id: int}
     */
    public function currentState(mixed $last_state): array {
        $checkpoint = $last_state === null ? 0 : (int) $last_state;
        $max_id     = (int) DB::table('log')->max('log_id');

        $rows = [];
        if ($checkpoint < $max_id) {
            foreach (DB::table('log')
                ->where('log_id', '>', $checkpoint)
                ->where('log_type', '=', $this->log_type)
                ->orderBy('log_id')
                ->limit(self::FETCH_LIMIT)
                ->get(['log_id', 'log_time', 'log_message', 'user_id', 'gedcom_id'])
                ->all() as $row) {
                $rows[] = [
                    'log_id'      => (int) $row->log_id,
                    'log_time'    => (string) $row->log_time,
                    'log_message' => (string) $row->log_message,
                    'user_id'     => $row->user_id === null ? null : (int) $row->user_id,
                    'gedcom_id'   => $row->gedcom_id === null ? null : (int) $row->gedcom_id,
                ];
            }
        }

        return ['rows' => $rows, 'max_id' => $max_id];
    }

    /**
     * @param mixed $last_state
     * @param mixed $current_state
     *
     * @return array{state: int, events: list<array<string, string|int>>}
     */
    public function compare(mixed $last_state, mixed $current_state): array {
        $current = is_array($current_state) ? $current_state : [];
        $rows    = is_array($current['rows'] ?? null) ? array_values((array) $current['rows']) : [];
        $max_id  = (int) ($current['max_id'] ?? 0);

        // First run: establish the baseline, fire nothing.
        if ($last_state === null) {
            return ['state' => $max_id, 'events' => []];
        }

        $checkpoint = (int) $last_state;

        // The table shrank below the checkpoint (TRUNCATE / DB restore):
        // re-baseline silently - the rows are gone, there is nothing to fire.
        if ($max_id < $checkpoint) {
            return ['state' => $max_id, 'events' => []];
        }

        $matched = array_values(array_filter(
            $rows,
            function (array $row): bool {
                return $this->matches((string) ($row['log_message'] ?? ''));
            },
        ));
        $slice   = array_slice($matched, 0, self::MAX_ROWS_PER_RUN);

        // Caught up = the whole window is known AND every matching row fits in
        // one run: the checkpoint may jump to the table's max. Otherwise it
        // advances only as far as the last fired row (a full window or the cap
        // may still hold matching rows for the next run).
        $caught_up = count($rows) < self::FETCH_LIMIT && count($matched) <= self::MAX_ROWS_PER_RUN;
        if ($caught_up) {
            $state = $max_id;
        } elseif ($slice !== []) {
            $state = (int) $slice[count($slice) - 1]['log_id'];
        } else {
            $state = $rows !== [] ? (int) $rows[count($rows) - 1]['log_id'] : $checkpoint;
        }

        $events = [];
        foreach ($slice as $row) {
            $events[] = $this->payload($row);
        }

        return ['state' => $state, 'events' => $events];
    }

    /**
     * Whether a log message matches this detector's prefix filter (an empty
     * filter matches every row of the type).
     */
    private function matches(string $message): bool {
        if ($this->prefixes === []) {
            return true;
        }
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($message, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event payload of one log row: the row itself, with the message
     * truncated and the nullable columns omitted when null.
     *
     * @param array{log_id: int, log_time: string, log_message: string, user_id: ?int, gedcom_id: ?int} $row
     *
     * @return array<string, string|int>
     */
    private function payload(array $row): array {
        $payload = [
            'log_id'      => (int) $row['log_id'],
            'log_time'    => (string) $row['log_time'],
            'log_message' => self::truncateMessage((string) $row['log_message'], self::MAX_MESSAGE_LEN),
        ];
        if ($row['gedcom_id'] !== null) {
            $payload['gedcom_id'] = (int) $row['gedcom_id'];
        }
        if ($row['user_id'] !== null) {
            $payload['user_id'] = (int) $row['user_id'];
        }

        return $payload;
    }

    /**
     * Byte-truncate a log message without splitting a UTF-8 sequence at the
     * cut (works without the mbstring extension, which the standalone tests
     * may not have).
     */
    public static function truncateMessage(string $message, int $max): string {
        if (strlen($message) <= $max) {
            return $message;
        }
        $cut = substr($message, 0, $max);

        // Drop an incomplete multi-byte sequence at the cut.
        return (string) preg_replace('/[\xF0-\xF7][\x80-\xBF]{0,2}$|[\xE0-\xEF][\x80-\xBF]?$|[\xC2-\xDF]$/', '', $cut);
    }
}
