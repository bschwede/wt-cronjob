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

use function max;

/**
 * Detects that media records were added, via the COUNT(*) of the media_file
 * table. State is the current count; an increase fires `media-added`. A pure
 * decrease (deletions) does not fire - the event name promises additions.
 */
class MediaFileCountDetector implements PseudoEventDetectorInterface {

    public function eventName(): string {
        return 'media-added';
    }

    public function label(): string {
        return 'Media files added';
    }

    public function currentState(): mixed {
        return (int) DB::table('media_file')->count();
    }

    /**
     * @param mixed $last_state
     * @param mixed $current_state
     *
     * @return array{detected: bool, state: mixed, payload: array<string, mixed>}
     */
    public function compare(mixed $last_state, mixed $current_state): array {
        $current = (int) $current_state;

        if ($last_state === null) {
            return ['detected' => false, 'state' => $current, 'payload' => []];
        }

        $last = (int) $last_state;

        return [
            'detected' => $current > $last,
            'state'    => $current,
            'payload'  => [
                'added' => max(0, $current - $last),
                'total' => $current,
            ],
        ];
    }
}
