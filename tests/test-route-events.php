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
// webtrees/DB: the map builder (buildMap: record-route rule {xref} +
// mutating handler prefix, curated tree-level allowlist, segment naming,
// collision suffixes, fallback), the orphan detector (orphanedEvents),
// the success-status gate (shouldFire) and the payload assembler
// (buildPayload). The request/DB-dependent path (maybeFire: setting,
// listener, EventQueue::push) is a target-machine check.
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
    // record routes: unique segments
    triple('/tree/{tree}/edit-note-object/{xref}', 'POST', $ns . 'EditNoteAction'),
    triple('/tree/{tree}/edit-note-object/{xref}', 'GET', $ns . 'EditNotePage'),           // GET excluded
    triple('/tree/{tree}/update-record/{xref}', 'POST', $ns . 'EditRecordAction'),
    triple('/tree/{tree}/update-fact/{xref}{/fact_id}', 'POST', $ns . 'EditFactAction'),  // optional segment
    triple('/tree/{tree}/edit-media-file/{xref}/{fact_id}', 'POST', $ns . 'EditMediaFileAction'),
    triple('/tree/{tree}/add-child-to-individual/{xref}', 'POST', $ns . 'AddChildToIndividualAction'),
    // record routes: colliding segments (delete, edit-raw)
    triple('/tree/{tree}/delete/{xref}', 'POST', $ns . 'DeleteRecord'),
    triple('/tree/{tree}/delete/{xref}/{fact_id}', 'POST', $ns . 'DeleteFact'),
    triple('/tree/{tree}/edit-raw/{xref}', 'POST', $ns . 'EditRawRecordAction'),
    triple('/tree/{tree}/edit-raw/{xref}/{fact_id}', 'POST', $ns . 'EditRawFactAction'),
    // record routes: §14 additions (Link / Paste / Reorder / Pending)
    triple('/tree/{tree}/paste-fact/{xref}', 'POST', $ns . 'PasteFact'),
    triple('/tree/{tree}/link-media-to-record/{xref}', 'POST', $ns . 'LinkMediaToRecordAction'),
    triple('/tree/{tree}/link-child-to-family/{xref}', 'POST', $ns . 'LinkChildToFamilyAction'),
    triple('/tree/{tree}/link-spouse-to-individual/{xref}', 'POST', $ns . 'LinkSpouseToIndividualAction'),
    triple('/tree/{tree}/reorder-children/{xref}', 'POST', $ns . 'ReorderChildrenAction'),
    triple('/tree/{tree}/reorder-spouses/{xref}', 'POST', $ns . 'ReorderFamiliesAction'),
    triple('/tree/{tree}/reorder-media/{xref}', 'POST', $ns . 'ReorderMediaAction'),
    triple('/tree/{tree}/reorder-media-files/{xref}', 'POST', $ns . 'ReorderMediaFilesAction'),
    triple('/tree/{tree}/reorder-names/{xref}', 'POST', $ns . 'ReorderNamesAction'),
    triple('/tree/{tree}/accept/{xref}', 'POST', $ns . 'PendingChangesAcceptRecord'),
    triple('/tree/{tree}/accept/{xref}/{change}', 'POST', $ns . 'PendingChangesAcceptChange'),
    triple('/tree/{tree}/reject/{xref}', 'POST', $ns . 'PendingChangesRejectRecord'),
    triple('/tree/{tree}/reject/{xref}/{change}', 'POST', $ns . 'PendingChangesRejectChange'),
    // excluded: no {xref} in the path (xref filter, §14)
    triple('/tree/{tree}/create-note-object', 'POST', $ns . 'CreateNoteAction'),
    triple('/tree/{tree}/add-unlinked-individual', 'POST', $ns . 'AddUnlinkedAction'),
    // excluded: handler prefix
    triple('/tree/{tree}/add-fact/{xref}', 'POST', $ns . 'SelectNewFact'),
    triple('/tree/{tree}/copy/{xref}/{fact_id}', 'POST', $ns . 'CopyFact'),
    // tree-level allowlist routes (§14)
    triple('/tree/{tree}/import', 'POST', $ns . 'ImportGedcomAction'),
    triple('/tree/{tree}/import', 'GET', $ns . 'ImportGedcomPage'),                        // GET excluded
    triple('/tree/{tree}/load', 'POST', $ns . 'GedcomLoad'),
    triple('/tree/{tree}/merge-step1', 'POST', $ns . 'MergeRecordsAction'),
    triple('/tree/{tree}/merge-step2', 'POST', $ns . 'MergeFactsAction'),
    triple('/tree/{tree}/search-replace', 'POST', $ns . 'SearchReplaceAction'),
    triple('/tree/{tree}/renumber', 'POST', $ns . 'RenumberTreeAction'),
    triple('/tree/{tree}/data-fix/{data_fix}/update', 'POST', $ns . 'DataFixUpdate'),
    triple('/tree/{tree}/data-fix/{data_fix}/update-all', 'POST', $ns . 'DataFixUpdateAll'),
    triple('/tree/{tree}/data-fix', 'POST', $ns . 'DataFixSelect'),                        // no-op redirect, not allowlisted
    triple('/tree/{tree}/accept', 'POST', $ns . 'PendingChangesAcceptTree'),
    triple('/tree/{tree}/reject', 'POST', $ns . 'PendingChangesRejectTree'),
    triple('/tree/{tree}/change-family-members', 'POST', $ns . 'ChangeFamilyMembersAction'),
    // excluded: path prefix
    triple('/trees/delete/{tree}', 'POST', $ns . 'DeleteTreeAction'),
    triple('/users/delete/{user_id}', 'POST', $ns . 'DeleteUser'),
    // excluded: handler namespace
    triple('/tree/{tree}/edit-note-object/{xref}', 'POST', 'Schwendinger\Other\EditNoteAction'),
    triple('/tree/{tree}/import', 'POST', 'Schwendinger\Other\ImportGedcomAction'),        // allowlist still needs the core ns
];

$map = RouteEventService::buildMap($routes);

// --- naming: unique segments ---------------------------------------------------
check('unique segment: bare name', ($map[$ns . 'EditNoteAction']['event'] ?? '') === '_route:edit-note-object');
check('path is carried along', ($map[$ns . 'EditNoteAction']['path'] ?? '') === '/tree/{tree}/edit-note-object/{xref}');
check('update-record mapped', ($map[$ns . 'EditRecordAction']['event'] ?? '') === '_route:update-record');
check('update-fact (optional segment, no collision): bare name', ($map[$ns . 'EditFactAction']['event'] ?? '') === '_route:update-fact');
check('add-child-to-individual mapped', ($map[$ns . 'AddChildToIndividualAction']['event'] ?? '') === '_route:add-child-to-individual');

// --- naming: §14 record routes (Link / Paste / Reorder) --------------------------
check('paste-fact mapped', ($map[$ns . 'PasteFact']['event'] ?? '') === '_route:paste-fact');
check('link-media-to-record mapped', ($map[$ns . 'LinkMediaToRecordAction']['event'] ?? '') === '_route:link-media-to-record');
check('link-child-to-family mapped', ($map[$ns . 'LinkChildToFamilyAction']['event'] ?? '') === '_route:link-child-to-family');
check('link-spouse-to-individual mapped', ($map[$ns . 'LinkSpouseToIndividualAction']['event'] ?? '') === '_route:link-spouse-to-individual');
check('reorder-children mapped', ($map[$ns . 'ReorderChildrenAction']['event'] ?? '') === '_route:reorder-children');
check('reorder-spouses mapped', ($map[$ns . 'ReorderFamiliesAction']['event'] ?? '') === '_route:reorder-spouses');
check('reorder-media mapped', ($map[$ns . 'ReorderMediaAction']['event'] ?? '') === '_route:reorder-media');
check('reorder-media-files mapped', ($map[$ns . 'ReorderMediaFilesAction']['event'] ?? '') === '_route:reorder-media-files');
check('reorder-names mapped', ($map[$ns . 'ReorderNamesAction']['event'] ?? '') === '_route:reorder-names');

// --- collision suffixes ---------------------------------------------------------
check('delete collision: xref suffix', ($map[$ns . 'DeleteRecord']['event'] ?? '') === '_route:delete-xref');
check('delete collision: xref+fact_id suffix', ($map[$ns . 'DeleteFact']['event'] ?? '') === '_route:delete-xref-fact_id');
check('edit-raw collision: xref suffix', ($map[$ns . 'EditRawRecordAction']['event'] ?? '') === '_route:edit-raw-xref');
check('edit-raw collision: xref+fact_id suffix', ($map[$ns . 'EditRawFactAction']['event'] ?? '') === '_route:edit-raw-xref-fact_id');

// --- accept/reject: record routes vs. tree-level bulk (3-way, one segment) ------
check('accept/{xref}: xref suffix', ($map[$ns . 'PendingChangesAcceptRecord']['event'] ?? '') === '_route:accept-xref');
check('accept/{xref}/{change}: xref+change suffix', ($map[$ns . 'PendingChangesAcceptChange']['event'] ?? '') === '_route:accept-xref-change');
check('reject/{xref}: xref suffix', ($map[$ns . 'PendingChangesRejectRecord']['event'] ?? '') === '_route:reject-xref');
check('reject/{xref}/{change}: xref+change suffix', ($map[$ns . 'PendingChangesRejectChange']['event'] ?? '') === '_route:reject-xref-change');
check('bulk accept (allowlist): bare name', ($map[$ns . 'PendingChangesAcceptTree']['event'] ?? '') === '_route:accept');
check('bulk reject (allowlist): bare name', ($map[$ns . 'PendingChangesRejectTree']['event'] ?? '') === '_route:reject');

// --- tree-level allowlist routes (§14) -------------------------------------------
check('import mapped (allowlist)', ($map[$ns . 'ImportGedcomAction']['event'] ?? '') === '_route:import');
check('load mapped (allowlist)', ($map[$ns . 'GedcomLoad']['event'] ?? '') === '_route:load');
check('merge-step1 mapped (allowlist)', ($map[$ns . 'MergeRecordsAction']['event'] ?? '') === '_route:merge-step1');
check('merge-step2 mapped (allowlist)', ($map[$ns . 'MergeFactsAction']['event'] ?? '') === '_route:merge-step2');
check('search-replace mapped (allowlist)', ($map[$ns . 'SearchReplaceAction']['event'] ?? '') === '_route:search-replace');
check('renumber mapped (allowlist)', ($map[$ns . 'RenumberTreeAction']['event'] ?? '') === '_route:renumber');
check('data-fix update: explicit slug', ($map[$ns . 'DataFixUpdate']['event'] ?? '') === '_route:data-fix-update');
check('data-fix update-all: explicit slug (distinct from update)', ($map[$ns . 'DataFixUpdateAll']['event'] ?? '') === '_route:data-fix-update-all');
check('change-family-members mapped (allowlist)', ($map[$ns . 'ChangeFamilyMembersAction']['event'] ?? '') === '_route:change-family-members');

// --- filters --------------------------------------------------------------------
check('GET page route excluded', !isset($map[$ns . 'EditNotePage']));
check('GET import page excluded', !isset($map[$ns . 'ImportGedcomPage']));
check('create-note-object excluded (xref filter, §14)', !isset($map[$ns . 'CreateNoteAction']));
check('add-unlinked-individual excluded (xref filter, §14)', !isset($map[$ns . 'AddUnlinkedAction']));
check('SelectNewFact excluded (prefix)', !isset($map[$ns . 'SelectNewFact']));
check('CopyFact excluded (prefix)', !isset($map[$ns . 'CopyFact']));
check('DataFixSelect excluded (not an allowlist path)', !isset($map[$ns . 'DataFixSelect']));
check('DeleteTree excluded (path prefix)', !isset($map[$ns . 'DeleteTreeAction']));
check('DeleteUser excluded (path prefix)', !isset($map[$ns . 'DeleteUser']));
check('foreign namespace excluded (record route)', !isset($map['Schwendinger\Other\EditNoteAction']));
check('foreign namespace excluded (allowlist route)', !isset($map['Schwendinger\Other\ImportGedcomAction']));
check('mapped count (22 record + 11 allowlist)', count($map) === 33);

// --- fallback ---------------------------------------------------------------------
$empty = RouteEventService::buildMap([]);
check('empty route table -> fallback map', count($empty) === 1 && isset($empty[$ns . 'EditNoteAction']));
check('fallback event name', ($empty[$ns . 'EditNoteAction']['event'] ?? '') === '_route:edit-note-object');

// standalone context: no webtrees container -> map() must fall back, not crash
check('mapFor: fallback available standalone', (RouteEventService::mapFor($ns . 'EditNoteAction')['event'] ?? '') === '_route:edit-note-object');
check('mapFor: unknown handler -> null', RouteEventService::mapFor($ns . 'EditFactPage') === null);
check('mapFor: empty handler -> null', RouteEventService::mapFor('') === null);

// --- orphanedEvents (§14: dead _route:* triggers against the live map) -----------
// Standalone, the live map is the fallback map (only _route:edit-note-object).
check('orphanedEvents: known route event kept', RouteEventService::orphanedEvents(['_route:edit-note-object']) === []);
check('orphanedEvents: unknown _route:* flagged', RouteEventService::orphanedEvents(['_route:create-media-object']) === ['_route:create-media-object']);
check('orphanedEvents: non-route domains ignored', RouteEventService::orphanedEvents(['cronjob:pseudo-events', 'linkenhancer:index-dirty']) === []);
check('orphanedEvents: empty/blank names ignored', RouteEventService::orphanedEvents(['', '   ']) === []);
check('orphanedEvents: mixed list', RouteEventService::orphanedEvents(['_route:edit-note-object', '_route:foo', 'cronjob:x', '_route:bar']) === ['_route:foo', '_route:bar']);
check('orphanedEvents: empty input', RouteEventService::orphanedEvents([]) === []);

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

// --- payloadKeys (§12: catalog payload derived from the route path) --------------
check('payloadKeys: record route ends with change_pending', RouteEventService::payloadKeys('/tree/{tree}/edit-note-object/{xref}') === ['tree', 'tree_name', 'xref', 'change_pending']);
check('payloadKeys: optional segment param + change_pending', RouteEventService::payloadKeys('/tree/{tree}/update-fact/{xref}{/fact_id}') === ['tree', 'tree_name', 'xref', 'fact_id', 'change_pending']);
check('payloadKeys: two trailing params + change_pending', RouteEventService::payloadKeys('/tree/{tree}/delete/{xref}/{fact_id}') === ['tree', 'tree_name', 'xref', 'fact_id', 'change_pending']);
check('payloadKeys: only tree (tree + tree_name)', RouteEventService::payloadKeys('/tree/{tree}/create-note-object') === ['tree', 'tree_name']);
check('payloadKeys: path order preserved', RouteEventService::payloadKeys('/tree/{tree}/edit-raw/{xref}/{fact_id}') === ['tree', 'tree_name', 'xref', 'fact_id', 'change_pending']);
check('payloadKeys: non-tree params kept as-is', RouteEventService::payloadKeys('/tree/{tree}/edit-media-file/{xref}/{fact_id}') === ['tree', 'tree_name', 'xref', 'fact_id', 'change_pending']);
check('payloadKeys: no placeholders -> empty', RouteEventService::payloadKeys('/tree/xref/edit') === []);
// tree-level allowlist routes carry no xref -> no change_pending
check('payloadKeys: tree-level import without change_pending', RouteEventService::payloadKeys('/tree/{tree}/import') === ['tree', 'tree_name']);
check('payloadKeys: tree-level data-fix without change_pending', RouteEventService::payloadKeys('/tree/{tree}/data-fix/{data_fix}/update') === ['tree', 'tree_name', 'data_fix']);

echo $failures === 0 ? "All route-events tests passed.\n" : "{$failures} route-events test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
