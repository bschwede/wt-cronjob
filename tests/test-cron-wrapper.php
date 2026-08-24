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

// Standalone test for the ScheduleService cron wrapper (nextRun /
// upcomingRuns / validateCron). No webtrees/DB needed for these methods.
//
// The cron evaluation itself comes from the bundled
// dragonmantank/cron-expression library. If its vendor folder has not been
// fetched yet (no internet on the target box) the test skips cleanly.
//
// Run: php modules_v4/cronjob/tests/test-cron-wrapper.php

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;

if (!ScheduleService::hasCronLibrary()) {
    echo "SKIP - cron-expression vendor not present. Fetch it per README.md (\"Bundled dependency\"), then re-run.\n";
    exit(0);
}

$failures = 0;

function check(string $name, bool $cond, mixed $actual = null): void {
    global $failures;
    if ($cond) {
        echo "ok   - {$name}\n";
    } else {
        $failures++;
        echo 'FAIL - ' . $name . ($actual !== null ? ' (got ' . var_export($actual, true) . ')' : '') . "\n";
    }
}

// Validation
check('valid cron accepted', ScheduleService::validateCron('*/30 * * * *') === true);
check('invalid cron rejected', (static function (): bool {
    try {
        ScheduleService::validateCron('not a cron');

        return false;
    } catch (DomainException) {
        return true;
    }
})());
check('invalid cron rejected (6 fields)', (static function (): bool {
    try {
        ScheduleService::validateCron('0 0 0 0 0 0');

        return false;
    } catch (DomainException) {
        return true;
    }
})());

// nextRun - basic
check('nextRun same day', ScheduleService::nextRun('0 4 * * *', '2026-01-01 00:00:00') === '2026-01-01 04:00:00', ScheduleService::nextRun('0 4 * * *', '2026-01-01 00:00:00'));
check('nextRun next day (strictly after)', ScheduleService::nextRun('0 4 * * *', '2026-01-01 04:00:00') === '2026-01-02 04:00:00', ScheduleService::nextRun('0 4 * * *', '2026-01-01 04:00:00'));

// nextRun - steps
check('nextRun step expression', ScheduleService::nextRun('*/30 * * * *', '2026-01-01 00:01:00') === '2026-01-01 00:30:00', ScheduleService::nextRun('*/30 * * * *', '2026-01-01 00:01:00'));
check('nextRun step across hour', ScheduleService::nextRun('*/30 * * * *', '2026-01-01 00:30:00') === '2026-01-01 01:00:00', ScheduleService::nextRun('*/30 * * * *', '2026-01-01 00:30:00'));

// nextRun - day of week
check('nextRun weekday (Mondays)', ScheduleService::nextRun('0 3 * * 1', '2026-01-01 00:00:00') === '2026-01-05 03:00:00', ScheduleService::nextRun('0 3 * * 1', '2026-01-01 00:00:00'));

// nextRun - day-of-month OR day-of-week (standard cron semantics)
check('nextRun dom/dow OR', ScheduleService::nextRun('0 0 13 * 5', '2026-01-01 00:00:00') === '2026-01-02 00:00:00', ScheduleService::nextRun('0 0 13 * 5', '2026-01-01 00:00:00'));

// nextRun - macro
check('nextRun macro @daily', ScheduleService::nextRun('@daily', '2026-01-01 06:00:00') === '2026-01-02 00:00:00', ScheduleService::nextRun('@daily', '2026-01-01 06:00:00'));

// nextRun - year rollover
check('nextRun year rollover', ScheduleService::nextRun('0 0 1 1 *', '2026-01-01 00:00:01') === '2027-01-01 00:00:00', ScheduleService::nextRun('0 0 1 1 *', '2026-01-01 00:00:01'));

// upcomingRuns
$upcoming = ScheduleService::upcomingRuns('0 */6 * * *', 4, '2026-01-01 00:00:00');
check('upcomingRuns sequence', $upcoming === ['2026-01-01 06:00:00', '2026-01-01 12:00:00', '2026-01-01 18:00:00', '2026-01-02 00:00:00'], $upcoming);

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED\n";
    exit(1);
}
echo "\nAll cron-wrapper tests passed.\n";
exit(0);
