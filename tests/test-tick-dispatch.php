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

// Regression guard for the Symfony dispatch wiring of cli/tick.php.
//
// Without a default command, `php tick.php` (no arguments) falls through to
// Symfony's built-in "list" command: it prints the command list, exits 0 and
// runs NO tick - a silent no-op when a caller forgets the "cron:tick"
// argument (this is exactly how the watch daemon ran idle while logging
// "tick exit=0"). A functional dispatch test needs the webtrees bootstrap +
// DB, which the standalone suites do not have - hence this static guard on
// the wiring lines.
//
// Run: php modules_v4/cronjob/tests/test-tick-dispatch.php

namespace {

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

    $src = (string) file_get_contents(__DIR__ . '/../cli/tick.php');

    check('tick.php registers the TickCommand', str_contains($src, '$application->addCommand(new TickCommand());'));
    check("tick.php sets 'cron:tick' as the default command (bare call runs the tick, not 'list')", (bool) preg_match("/setDefaultCommand\(\s*'cron:tick'\s*\)/", $src));
    check("tick.php does not use single-command mode (', true' would reject the explicit 'cron:tick' of every existing caller)", !str_contains($src, "'cron:tick', true"));
    check('header usage example shows the cron:tick argument', (bool) preg_match('/^\/\/   php modules_v4\/cronjob\/cli\/tick\.php cron:tick /m', $src));
    check('header cron example shows the cron:tick argument', (bool) preg_match('/^\/\/   cron:.*tick\.php cron:tick /m', $src));

    if ($failures === 0) {
        echo "All tick-dispatch tests passed.\n";
    } else {
        echo "{$failures} test(s) FAILED.\n";
    }
    exit($failures === 0 ? 0 : 1);
}
