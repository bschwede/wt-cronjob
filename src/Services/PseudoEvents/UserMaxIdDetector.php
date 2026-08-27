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
 * Detects that a user registered, via MAX(user_id) on the user table
 * (user_id is auto-increment, so it only ever grows as new users sign up).
 * State is the highest user_id; a higher maximum fires `cronjob:user-registered`.
 */
class UserMaxIdDetector implements PseudoEventDetectorInterface {

    public function eventName(): string {
        return 'cronjob:user-registered';
    }

    public function label(): string {
        return 'New user registered';
    }

    public function currentState(): mixed {
        return (int) DB::table('user')->max('user_id');
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
                'new_user_id' => $current,
                'added'       => max(0, $current - $last),
            ],
        ];
    }
}
