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

// Standalone test for EventQueue::coalesceRows() (§16, F1): the event-queue
// coalescing keeps at most one (the newest) pending row per event_name, so a
// flood of identical events cannot amplify into one job run per queued row.
// Pure function - no webtrees/DB needed.
//
// Run: php modules_v4/cronjob/tests/test-event-queue.php

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue;

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

/** A cj_event-shaped row. */
function row(int $id, string $event_name): object {
    return (object) ['id' => $id, 'event_name' => $event_name];
}

/** @param list<object> $rows */
function ids(array $rows): array {
    return array_map(static fn (object $r): int => (int) $r->id, $rows);
}

// 1. Empty input
check('empty list -> empty', EventQueue::coalesceRows([]) === []);

// 2. Single row passes through
$kept = EventQueue::coalesceRows([row(5, 'a')]);
check('single row kept', $kept !== [] && (int) $kept[0]->id === 5);

// 3. Same name, several rows -> only the newest (max id)
$kept = EventQueue::coalesceRows([row(1, 'a'), row(3, 'a'), row(2, 'a')]);
check('same name -> newest only', ids($kept) === [3]);

// 4. Several names, given in arbitrary order -> one per name, oldest first
$kept = EventQueue::coalesceRows([
    row(9, 'c'),
    row(4, 'b'),
    row(1, 'a'),
    row(7, 'c'),
    row(6, 'b'),
    row(2, 'a'),
]);
// Newest per name: a -> 2, b -> 6, c -> 9; ordered oldest first.
check('one row per name, oldest first', ids($kept) === [2, 6, 9]);
$names = array_map(static fn (object $r): string => (string) $r->event_name, $kept);
check('names match the kept ids', $names === ['a', 'b', 'c']);

// 5. Flood: 50 rows of one name -> exactly one (the newest)
$flood = [];
for ($i = 1; $i <= 50; $i++) {
    $flood[] = row($i, '_route:edit-note-object');
}
$kept = EventQueue::coalesceRows($flood);
check('flood of one name coalesces to one', count($kept) === 1 && (int) $kept[0]->id === 50);

// 6. Limit caps the number of DISTINCT names (deterministic: oldest kept first)
$kept = EventQueue::coalesceRows([row(10, 'd'), row(1, 'a'), row(5, 'b'), row(3, 'c')], 2);
check('limit 2 of 4 names -> the two oldest', ids($kept) === [1, 3]);

// 7. Limit larger than the name count -> everything
$kept = EventQueue::coalesceRows([row(1, 'a'), row(2, 'b')], 100);
check('limit above name count -> all', ids($kept) === [1, 2]);

// 8. The kept row is the newest of its name even when a newer name sorts first
$kept = EventQueue::coalesceRows([row(2, 'a'), row(10, 'z'), row(1, 'a')], 100);
check('per-name newest independent of order', in_array(2, ids($kept), true) && in_array(10, ids($kept), true) && count($kept) === 2);

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED\n";
    exit(1);
}
echo "\nAll event-queue tests passed.\n";
exit(0);
