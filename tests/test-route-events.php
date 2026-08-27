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
// webtrees/DB: the rule-based map builder (buildMap: path/POST/handler
// filters, segment naming, collision suffixes, fallback), the
// success-status gate (shouldFire) and the payload assembler (buildPayload).
// The request/DB-dependent path (maybeFire: setting, listener,
// EventQueue::push) is a target-machine check.
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

function triple(string $path, string $method, string $handler): array {
    return ['path' => $path, 'allows' => [$method], 'handler' => $handler];
}

$ns = 'Fisharebest\Webtrees\Http\RequestHandlers\\';

// A slice of the real webtrees /tree/{tree} route table (WebRoutes.php),
// plus the distractors the rules must exclude.
$routes = [
    // mapped: unique segment
    triple('/tree/{tree}/edit-note-object/{xref}', 'POST', $ns . 'EditNoteAction'),
    triple('/tree/{tree}/edit-note-object/{xref}', 'GET', $ns . 'EditNotePage'),          // GET excluded
    triple('/tree/{tree}/update-record/{xref}', 'POST', $ns . 'EditRecordAction'),
    triple('/tree/{tree}/update-fact/{xref}{/fact_id}', 'POST', $ns . 'EditFactAction'), // optional segment
    triple('/tree/{tree}/edit-media-file/{xref}/{fact_id}', 'POST', $ns . 'EditMediaFileAction'),
    triple('/tree/{tree}/add-child-to-individual/{xref}', 'POST', $ns . 'AddChildToIndividualAction'),
    triple('/tree/{tree}/create-note-object', 'POST', $ns . 'CreateNoteAction'),
    // mapped: colliding segments (delete, edit-raw)
    triple('/tree/{tree}/delete/{xref}', 'POST', $ns . 'DeleteRecord'),
    triple('/tree/{tree}/delete/{xref}/{fact_id}', 'POST', $ns . 'DeleteFact'),
    triple('/tree/{tree}/edit-raw/{xref}', 'POST', $ns . 'EditRawRecordAction'),
    triple('/tree/{tree}/edit-raw/{xref}/{fact_id}', 'POST', $ns . 'EditRawFactAction'),
    // excluded: handler prefix
    triple('/tree/{tree}/add-fact/{xref}', 'POST', $ns . 'SelectNewFact'),
    triple('/tree/{tree}/copy/{xref}/{fact_id}', 'POST', $ns . 'CopyFact'),
    triple('/tree/{tree}/paste-fact/{xref}', 'POST', $ns . 'PasteFact'),
    // excluded: path prefix
    triple('/trees/delete/{tree}', 'POST', $ns . 'DeleteTreeAction'),
    triple('/users/delete/{user_id}', 'POST', $ns . 'DeleteUser'),
    // excluded: handler namespace
    triple('/tree/{tree}/edit-note-object/{xref}', 'POST', 'Schwendinger\Other\EditNoteAction'),
];

$map = RouteEventService::buildMap($routes);

// --- naming -------------------------------------------------------------------
check('unique segment: bare name', ($map[$ns . 'EditNoteAction']['event'] ?? '') === '_route:edit-note-object');
check('path is carried along', ($map[$ns . 'EditNoteAction']['path'] ?? '') === '/tree/{tree}/edit-note-object/{xref}');
check('update-record mapped', ($map[$ns . 'EditRecordAction']['event'] ?? '') === '_route:update-record');
check('update-fact (optional segment, no collision): bare name', ($map[$ns . 'EditFactAction']['event'] ?? '') === '_route:update-fact');
check('add-child-to-individual mapped', ($map[$ns . 'AddChildToIndividualAction']['event'] ?? '') === '_route:add-child-to-individual');
check('create-note-object mapped (no xref)', ($map[$ns . 'CreateNoteAction']['event'] ?? '') === '_route:create-note-object');

// --- collision suffixes ---------------------------------------------------------
check('delete collision: xref suffix', ($map[$ns . 'DeleteRecord']['event'] ?? '') === '_route:delete-xref');
check('delete collision: xref+fact_id suffix', ($map[$ns . 'DeleteFact']['event'] ?? '') === '_route:delete-xref-fact_id');
check('edit-raw collision: xref suffix', ($map[$ns . 'EditRawRecordAction']['event'] ?? '') === '_route:edit-raw-xref');
check('edit-raw collision: xref+fact_id suffix', ($map[$ns . 'EditRawFactAction']['event'] ?? '') === '_route:edit-raw-xref-fact_id');

// --- filters --------------------------------------------------------------------
check('GET page route excluded', !isset($map[$ns . 'EditNotePage']));
check('SelectNewFact excluded (prefix)', !isset($map[$ns . 'SelectNewFact']));
check('CopyFact excluded (prefix)', !isset($map[$ns . 'CopyFact']));
check('PasteFact excluded (prefix)', !isset($map[$ns . 'PasteFact']));
check('DeleteTree excluded (path prefix)', !isset($map[$ns . 'DeleteTreeAction']));
check('DeleteUser excluded (path prefix)', !isset($map[$ns . 'DeleteUser']));
check('foreign namespace excluded', !isset($map['Schwendinger\Other\EditNoteAction']));
check('mapped count', count($map) === 10);

// --- fallback ---------------------------------------------------------------------
$empty = RouteEventService::buildMap([]);
check('empty route table -> fallback map', count($empty) === 1 && isset($empty[$ns . 'EditNoteAction']));
check('fallback event name', ($empty[$ns . 'EditNoteAction']['event'] ?? '') === '_route:edit-note-object');

// standalone context: no webtrees container -> map() must fall back, not crash
check('mapFor: fallback available standalone', (RouteEventService::mapFor($ns . 'EditNoteAction')['event'] ?? '') === '_route:edit-note-object');
check('mapFor: unknown handler -> null', RouteEventService::mapFor($ns . 'EditFactPage') === null);
check('mapFor: empty handler -> null', RouteEventService::mapFor('') === null);

// --- shouldFire -----------------------------------------------------------------
check('shouldFire: 200 fires', RouteEventService::shouldFire(200) === true);
check('shouldFire: 302 redirect fires', RouteEventService::shouldFire(302) === true);
check('shouldFire: 301 fires', RouteEventService::shouldFire(301) === true);
check('shouldFire: 400 does not fire', RouteEventService::shouldFire(400) === false);
check('shouldFire: 404 does not fire', RouteEventService::shouldFire(404) === false);
check('shouldFire: 500 does not fire', RouteEventService::shouldFire(500) === false);

// --- buildPayload ------------------------------------------------------------------
$flat = [
    'tree'      => '5',
    'tree_name' => 'Family',
    'xref'      => '@I1@',
    'fact_id'   => 12,
    'sex'       => 'i',
    'flag'      => true,
    'route'     => new stdClass(),
    'nothing'   => null,
];
$payload = RouteEventService::buildPayload($flat);
check('buildPayload: keeps scalars', $payload === ['tree' => '5', 'tree_name' => 'Family', 'xref' => '@I1@', 'fact_id' => 12, 'sex' => 'i', 'flag' => true]);
check('buildPayload: drops objects', !array_key_exists('route', $payload));
check('buildPayload: drops null', !array_key_exists('nothing', $payload));
check('buildPayload: empty in -> empty out', RouteEventService::buildPayload([]) === []);

echo $failures === 0 ? "All route-events tests passed.\n" : "{$failures} route-events test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
