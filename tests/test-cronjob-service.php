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

// Standalone guard for the public service API (CronjobService).
//
// The facade must keep a stable, self-describing surface for neighboring
// modules: every promised method public + static, the stale-tick constant,
// the tick.last marker wired into TickCommand, the DataFiles docblock, and
// a public RouteEventService::isEnabled(). No webtrees vendor / DB needed:
// the facade file is loaded directly (its dependencies are only touched at
// method execution time) and the rest is a static guard on the sources.
//
// Run: php modules_v4/cronjob/tests/test-cronjob-service.php

namespace {

    require __DIR__ . '/../src/CronjobService.php';

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

    $class = new ReflectionClass(Schwendinger\Webtrees\Module\Cronjob\CronjobService::class);
    check('CronjobService is a final class', $class->isFinal());

    $expected = [
        'isEnabled', 'isMigrated', 'isFunctional', 'problems',
        'features', 'watchStatus',
        'lastTick', 'lastTickAge',
        'jobNames', 'isJobEnabled', 'jobStatus', 'lastRun',
        'listenedEvents', 'listenersFor', 'eventCatalog', 'pseudoEvents', 'routeEvents',
        'runJobNow', 'pushEvent',
    ];
    foreach ($expected as $method) {
        check("method {$method}() is public static", $class->hasMethod($method)
            && $class->getMethod($method)->isPublic()
            && $class->getMethod($method)->isStatic());
    }

    check('TICK_STALE_SECONDS is 300', (int) Schwendinger\Webtrees\Module\Cronjob\CronjobService::TICK_STALE_SECONDS === 300);

    $api_src = (string) file_get_contents(__DIR__ . '/../src/CronjobService.php');
    check('API carries no translated strings (source strings only)', !str_contains($api_src, 'I18N::translate'));
    check('lastRun() does not select the output column', !str_contains($api_src, "'output'"));

    $tick_src = (string) file_get_contents(__DIR__ . '/../src/Services/TickCommand.php');
    check('TickCommand touches the tick.last marker', str_contains($tick_src, "@touch(DataFiles::path('tick.last'))"));

    $data_src = (string) file_get_contents(__DIR__ . '/../src/Services/DataFiles.php');
    check('DataFiles docblock lists tick.last', str_contains($data_src, 'data/cronjob/tick.last'));

    $route_src = (string) file_get_contents(__DIR__ . '/../src/Services/RouteEventService.php');
    check('RouteEventService::isEnabled() is public', (bool) preg_match('/public static function isEnabled\(\): bool/', $route_src));

    if ($failures === 0) {
        echo "All cronjob-service tests passed.\n";
    } else {
        echo "{$failures} test(s) FAILED.\n";
    }
    exit($failures === 0 ? 0 : 1);
}
