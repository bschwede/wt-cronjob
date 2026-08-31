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

// Standalone test for the manifest i18n pipeline:
//   a) MoreI18N::translate() is a pure identity (extraction marker only),
//   b) cron-jobs.php still returns the exact English literals and shape,
//   c) CronjobUtils::translateJobTitle() guards '%' titles (I18N::translate()
//      applies sprintf() to its result - a bare '%' in an admin-renamed
//      title would be an invalid conversion specification).
// No webtrees/DB needed; the Fisharebest\Webtrees\I18N below is a stub that
// stands in for the real class in the non-guarded branch.
//
// Run: php modules_v4/cronjob/tests/test-i18n-mark.php

namespace Fisharebest\Webtrees {
    class I18N {
        public static int $calls = 0;

        public static function translate(string $message, ...$args): string {
            self::$calls++;
            return 'TR:' . $message;
        }
    }
}

namespace {

    require __DIR__ . '/../autoload.php';
    // Load the class directly (not via the module autoloader) so the I18N
    // stub above is what translateJobTitle() resolves against.
    require __DIR__ . '/../src/CronjobUtils.php';

    use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
    use Schwendinger\Webtrees\Module\Cronjob\MoreI18N;

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

    // 1. MoreI18N is a pure identity (plain, unicode, '%' chars)
    check('marker identity (plain)', MoreI18N::translate('Cronjob: Pseudo-Events (log-table pollers)') === 'Cronjob: Pseudo-Events (log-table pollers)');
    check('marker identity (unicode)', MoreI18N::translate('Überprüfung äöü') === 'Überprüfung äöü');
    check('marker identity (percent)', MoreI18N::translate('100%') === '100%');

    // 2. The manifest is still pure data: exact English literals, intact shape
    $manifest = include __DIR__ . '/../cron-jobs.php';
    check('manifest is an array', is_array($manifest));
    check('manifest keys jobs/commands', isset($manifest['jobs'], $manifest['commands']));
    check('one job, one pseudo-events', count($manifest['jobs']) === 1 && $manifest['jobs'][0]['name'] === 'pseudo-events');
    check(
        'job title literal unchanged',
        $manifest['jobs'][0]['title'] === 'Cronjob: Pseudo-Events (log-table pollers)'
    );
    check('two announced commands', count($manifest['commands']) === 2);
    check(
        'command description literal unchanged',
        $manifest['commands'][0]['description'] === 'Poll the webtrees log table (failed logins, logins, logouts, errors, record edits, searches) and queue one event per new matching log entry for the tick.'
    );
    check(
        'param description literal unchanged',
        $manifest['commands'][0]['params'][0]['description'] === 'Bypass the 5-minute cooldown (a manual run).'
    );
    check(
        'second command description literal unchanged',
        $manifest['commands'][1]['description'] === 'Acceptance-test job for the cronjob module. It only prints and exits 0.'
    );
    check(
        'second param description literal unchanged',
        $manifest['commands'][1]['params'][0]['description'] === 'keeps the process alive for N seconds (timeout testing)'
    );

    // 3. translateJobTitle: '%' titles bypass translation entirely
    //    (the guard must not touch I18N - a real I18N::translate() of a bare
    //    '%' would be an invalid sprintf conversion)
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check(
        'title with %% -> unchanged, I18N untouched',
        CronjobUtils::translateJobTitle('Backup 100%') === 'Backup 100%' && \Fisharebest\Webtrees\I18N::$calls === 0
    );

    // 4. translateJobTitle: '%' -free titles go through the translator
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check(
        'title without %% -> translated at render time',
        CronjobUtils::translateJobTitle('Cronjob: Pseudo-Events (log-table pollers)') === 'TR:Cronjob: Pseudo-Events (log-table pollers)' && \Fisharebest\Webtrees\I18N::$calls === 1
    );

    if ($failures > 0) {
        echo "\n{$failures} test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll i18n-mark tests passed.\n";
    exit(0);
}
