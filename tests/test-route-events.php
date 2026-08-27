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

// Standalone tests for the route-event building blocks that are free of
// webtrees/DB: the curated map lookup (mapFor), the success-status gate
// (shouldFire) and the pure payload assembler (buildPayload). The
// request/DB-dependent path (maybeFire: setting, listener, EventQueue::push)
// is a target-machine check.
//
// Run: php modules_v4/cronjob/tests/test-route-events.php

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\RouteEventService;

$failures = 0;

function check(string $name, bool $cond): void {
    global $failures;
    if ($cond) {
        echo "ok   - {$name}\n";
    } else {
        $failures++;
        echo "FAIL - {$name}\n";
    }
}

// --- map / mapFor ---------------------------------------------------------------
$note = 'Fisharebest\Webtrees\Http\RequestHandlers\EditNoteAction';
check('mapFor: edit-note-object is curated', RouteEventService::mapFor($note) !== null);
$entry = RouteEventService::mapFor($note);
check('mapFor: event name', $entry['event'] === 'edit-note-object');
check('mapFor: payload keys', $entry['payload'] === ['tree', 'xref']);
check('mapFor: unknown handler -> null', RouteEventService::mapFor('Fisharebest\Webtrees\Http\RequestHandlers\EditFactAction') === null);
check('mapFor: empty handler -> null', RouteEventService::mapFor('') === null);

// --- shouldFire -----------------------------------------------------------------
check('shouldFire: 200 fires', RouteEventService::shouldFire(200) === true);
check('shouldFire: 302 redirect fires', RouteEventService::shouldFire(302) === true);
check('shouldFire: 301 fires', RouteEventService::shouldFire(301) === true);
check('shouldFire: 400 does not fire', RouteEventService::shouldFire(400) === false);
check('shouldFire: 404 does not fire', RouteEventService::shouldFire(404) === false);
check('shouldFire: 500 does not fire', RouteEventService::shouldFire(500) === false);

// --- buildPayload ----------------------------------------------------------------
$flat = ['tree' => '5', 'tree_name' => 'Family', 'xref' => '@I1@'];
check('buildPayload: picks requested keys only',
    RouteEventService::buildPayload($flat, ['tree', 'xref']) === ['tree' => '5', 'xref' => '@I1@']);
check('buildPayload: missing key omitted',
    RouteEventService::buildPayload(['xref' => '@I1@'], ['tree', 'xref']) === ['xref' => '@I1@']);
check('buildPayload: no keys -> empty', RouteEventService::buildPayload($flat, []) === []);
check('buildPayload: unknown key omitted',
    RouteEventService::buildPayload($flat, ['nope']) === []);

echo $failures === 0 ? "All route-events tests passed.\n" : "{$failures} route-events test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
