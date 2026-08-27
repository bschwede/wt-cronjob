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

// Standalone tests for the phase-2 pseudo-events building blocks that are
// free of webtrees/DB: the detectors' pure compare() transition logic,
// ScheduleService::specDiff() and the PseudoEventService file-based state /
// cooldown handling. A minimal Webtrees constant stub stands in for the core
// class (same convention as test-watch-service.php); state files live in a
// temp dir the test creates and removes. The DB-dependent paths (currentState,
// activeEventNames, EventQueue::push) are target-machine checks.
//
// Run: php modules_v4/cronjob/tests/test-pseudo-events.php

namespace Fisharebest\Webtrees {
    if (!class_exists('Fisharebest\\Webtrees\\Webtrees', false)) {
        class Webtrees {
            public const DATA_DIR = __DIR__ . '/.pevents/data/';
            public const ROOT_DIR = __DIR__ . '/.pevents/';
        }
    }
}

namespace {

    require __DIR__ . '/../autoload.php';

    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\GedcomFileDetector;
    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\MediaFileCountDetector;
    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\PseudoEventService;
    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\UserMaxIdDetector;
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

    $base = __DIR__ . '/.pevents';
    $data = $base . '/data/';
    @mkdir($data, 0777, true);
    foreach (glob($data . 'cronjob-pseudo-events*') ?: [] as $file) {
        @unlink($file);
    }

    // --- detectors: event names --------------------------------------------
    check('gedcom detector event name', (new GedcomFileDetector())->eventName() === 'cronjob:gedcom-changed');
    check('media detector event name', (new MediaFileCountDetector())->eventName() === 'cronjob:media-added');
    check('user detector event name', (new UserMaxIdDetector())->eventName() === 'cronjob:user-registered');

    // --- GedcomFileDetector::compare ----------------------------------------
    $ged = new GedcomFileDetector();
    $tree1 = [1 => ['mtime' => 100, 'size' => 10, 'name' => 'tree1']];

    $baseline = $ged->compare(null, $tree1);
    check('gedcom: first run is baseline (not detected)', $baseline['detected'] === false);
    check('gedcom: baseline state kept', $baseline['state'] === $tree1);

    check('gedcom: unchanged -> not detected', $ged->compare($tree1, $tree1)['detected'] === false);

    $mtime = $ged->compare($tree1, [1 => ['mtime' => 200, 'size' => 10, 'name' => 'tree1']]);
    check('gedcom: mtime change -> detected', $mtime['detected'] === true);
    check('gedcom: changed payload lists tree', $mtime['payload']['changed'] === ['tree1']);

    $size = $ged->compare($tree1, [1 => ['mtime' => 100, 'size' => 999, 'name' => 'tree1']]);
    check('gedcom: size change -> detected', $size['detected'] === true);

    $added = $ged->compare($tree1, [1 => ['mtime' => 100, 'size' => 10, 'name' => 'tree1'], 2 => ['mtime' => 5, 'size' => 5, 'name' => 'tree2']]);
    check('gedcom: new tree -> detected', $added['detected'] === true);
    check('gedcom: new tree in payload', in_array('tree2', $added['payload']['changed'], true));

    check('gedcom: removed tree -> detected', $ged->compare($tree1, [])['detected'] === true);

    // --- MediaFileCountDetector::compare ------------------------------------
    $media = new MediaFileCountDetector();
    check('media: baseline not detected', $media->compare(null, 42)['detected'] === false);
    check('media: unchanged not detected', $media->compare(42, 42)['detected'] === false);
    $inc = $media->compare(42, 50);
    check('media: increase detected', $inc['detected'] === true);
    check('media: added payload', $inc['payload'] === ['added' => 8, 'total' => 50]);
    check('media: decrease not detected', $media->compare(50, 40)['detected'] === false);

    // --- UserMaxIdDetector::compare -----------------------------------------
    $user = new UserMaxIdDetector();
    check('user: baseline not detected', $user->compare(null, 7)['detected'] === false);
    check('user: unchanged not detected', $user->compare(7, 7)['detected'] === false);
    $up = $user->compare(7, 9);
    check('user: new max detected', $up['detected'] === true);
    check('user: payload', $up['payload'] === ['new_user_id' => 9, 'added' => 2]);
    check('user: max decrease not detected', $user->compare(9, 5)['detected'] === false);

    // --- ScheduleService::specDiff ------------------------------------------
    $spec = [
        'name'         => 'pseudo-events',
        'title'        => 'T',
        'trigger_type' => 'time',
        'event_name'   => null,
        'cron'         => '*/5 * * * *',
        'command_type' => 'module',
        'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
        'args'         => '',
        'enabled'      => false,
        'timeout_sec'  => 120,
    ];
    $identical = [
        'title'        => 'T',
        'trigger_type' => 'time',
        'event_name'   => null,
        'cron'         => '*/5 * * * *',
        'command_type' => 'module',
        'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
        'args'         => '',
        'timeout_sec'  => 120,
    ];

    check('specDiff: identical -> no diff', ScheduleService::specDiff($identical, $spec) === []);

    $cron_changed = $identical;
    $cron_changed['cron'] = '*/10 * * * *';
    check('specDiff: cron change -> [cron]', ScheduleService::specDiff($cron_changed, $spec) === ['cron']);

    $timeout_changed = $identical;
    $timeout_changed['timeout_sec'] = 60;
    check('specDiff: timeout change -> [timeout_sec]', ScheduleService::specDiff($timeout_changed, $spec) === ['timeout_sec']);

    $timeout_str = $identical;
    $timeout_str['timeout_sec'] = '120';
    check('specDiff: timeout int vs string equal -> no diff', ScheduleService::specDiff($timeout_str, $spec) === []);

    $multi = $identical;
    $multi['title'] = 'X';
    $multi['args']  = '--x';
    check('specDiff: multiple changes in manifest order', ScheduleService::specDiff($multi, $spec) === ['title', 'args']);

    $ev_row = $identical;
    $ev_row['event_name'] = '';
    check('specDiff: time job event_name null=="" no diff', ScheduleService::specDiff($ev_row, $spec) === []);

    $ev_spec   = $spec;
    $ev_spec['trigger_type'] = 'event';
    $ev_spec['event_name']   = 'gedcom-changed';
    $ev_spec['cron']         = '';
    $ev_diff = ScheduleService::specDiff($identical, $ev_spec);
    check('specDiff: time->event job flags trigger_type+event_name+cron',
        in_array('trigger_type', $ev_diff, true) && in_array('event_name', $ev_diff, true) && in_array('cron', $ev_diff, true));

    // --- PseudoEventService file-based logic --------------------------------
    check('service: cooldown elapsed when no last file', PseudoEventService::cooldownElapsed() === true);
    touch($data . 'cronjob-pseudo-events-last');
    clearstatcache();
    check('service: cooldown active right after a run', PseudoEventService::cooldownElapsed() === false);
    touch($data . 'cronjob-pseudo-events-last', time() - 400);
    clearstatcache();
    check('service: cooldown elapsed after the interval', PseudoEventService::cooldownElapsed() === true);

    check('service: loadState default when no file', PseudoEventService::loadState() === ['last_run_at' => 0, 'detectors' => []]);
    PseudoEventService::saveState(['last_run_at' => 12345, 'detectors' => ['cronjob:media-added' => 5, 'cronjob:user-registered' => 7]]);
    $loaded = PseudoEventService::loadState();
    check('service: state round-trip', $loaded === ['last_run_at' => 12345, 'detectors' => ['cronjob:media-added' => 5, 'cronjob:user-registered' => 7]]);

    $list  = PseudoEventService::detectors();
    $names = [];
    foreach ($list as $detector) {
        $names[] = $detector->eventName();
    }
    check('service: three detectors registered in order',
        count($list) === 3 && $names === ['cronjob:gedcom-changed', 'cronjob:media-added', 'cronjob:user-registered']);

    // Cleanup
    foreach (glob($data . 'cronjob-pseudo-events*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($data);
    @rmdir($base);

    echo $failures === 0 ? "All pseudo-events tests passed.\n" : "{$failures} test(s) FAILED.\n";
    exit($failures === 0 ? 0 : 1);
}
