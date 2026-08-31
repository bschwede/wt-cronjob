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

namespace Fisharebest\Webtrees {
    // Test double for webtrees' I18N. The real I18N::translate() / plural()
    // return sprintf(original, ...args) when no translation is loaded; plural()
    // picks singular/plural by the locale's rules (English: count === 1). This
    // shim reproduces exactly that, so the assertions below (English output)
    // verify which template humanizeCron() picks and which args it passes.
    class I18N {
        public static function translate(string $message, ...$args): string {
            return $args === [] ? $message : sprintf($message, ...$args);
        }

        public static function plural(string $singular, string $plural, int $count, ...$args): string {
            $message = $count === 1 ? $singular : $plural;

            return $args === [] ? $message : sprintf($message, ...$args);
        }
    }
}

namespace {
    require __DIR__ . '/../autoload.php';

    use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;

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

    function hz(string $cron): string {
        return ScheduleService::humanizeCron($cron);
    }

    // macros
    check('@daily', hz('@daily') === 'daily at 00:00');
    check('@midnight', hz('@midnight') === 'daily at 00:00');
    check('@hourly', hz('@hourly') === 'hourly');
    check('@minutely', hz('@minutely') === 'every minute');
    check('@weekly', hz('@weekly') === 'weekly on Sunday at 00:00');
    check('@monthly', hz('@monthly') === 'on day 1 of each month at 00:00');

    // every N minutes / every minute
    check('every minute (wildcard)', hz('* * * * *') === 'every minute');
    check('every 30 minutes', hz('*/30 * * * *') === 'every 30 minutes');
    check('every 15 minutes', hz('*/15 * * * *') === 'every 15 minutes');
    check('every 1 minute (step)', hz('*/1 * * * *') === 'every minute');

    // hourly / every hour at :MM
    check('hourly (0 * * * *)', hz('0 * * * *') === 'hourly');
    check('every hour at minute 30', hz('30 * * * *') === 'every hour at minute 30');

    // daily at HH:MM
    check('daily at 04:00', hz('0 4 * * *') === 'daily at 04:00');
    check('daily at 04:05', hz('5 4 * * *') === 'daily at 04:05');

    // on <weekday> at HH:MM
    check('on Sunday at 03:00', hz('0 3 * * 0') === 'on Sunday at 03:00');
    check('on Monday at 09:30', hz('30 9 * * 1') === 'on Monday at 09:30');
    check('Sunday is dow 7 too', hz('0 3 * * 7') === 'on Sunday at 03:00');
    check('weekday range', hz('0 12 * * 1-5') === 'on Monday to Friday at 12:00');
    check('dow list of two', hz('0 8 * * 0,6') === 'on Sunday and Saturday at 08:00');
    check('dow list of three', hz('0 8 * * 1,3,5') === 'on Monday, Wednesday, Friday at 08:00');

    // on day D of each month
    check('on day 15 of each month', hz('0 8 15 * *') === 'on day 15 of each month at 08:00');

    // fallbacks: unknown shapes return the raw string unchanged
    check('fallback: month list', hz('0 0 1 1,6 *') === '0 0 1 1,6 *');
    check('fallback: yearly literal', hz('0 0 1 1 *') === '0 0 1 1 *');
    check('fallback: 6 fields', hz('0 0 4 * * *') === '0 0 4 * * *');
    check('fallback: step hour', hz('0 */6 * * *') === '0 */6 * * *');
    check('empty -> empty', hz('   ') === '');

    echo $failures === 0 ? "All cron-humanize tests passed.\n" : "{$failures} cron-humanize test(s) FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
