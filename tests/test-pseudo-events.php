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

// Standalone tests for the pseudo-events building blocks that are free of
// webtrees/DB: the LogRowDetector's pure compare() transition logic
// (checkpoint, backpressure cap, reset, payload shape, truncation), the
// detector registry / orphan / state-pruning helpers,
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

    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\LogRowDetector;
    use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\PseudoEventService;
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

    // --- registry -------------------------------------------------------------------
    $list  = PseudoEventService::detectors();
    $names = [];
    foreach ($list as $detector) {
        $names[] = $detector->eventName();
    }
    $expected = [
        'cronjob:log-auth-failed',
        'cronjob:log-auth-login',
        'cronjob:log-auth-logout',
        'cronjob:log-error',
        'cronjob:log-edit-update',
        'cronjob:log-edit-delete',
        'cronjob:log-search',
    ];
    check('registry: seven detectors in order', $names === $expected);
    check('registry: old events gone',
        array_intersect(['cronjob:gedcom-changed', 'cronjob:media-added', 'cronjob:user-registered'], $names) === []);

    foreach ($list as $detector) {
        check('catalog: description set for ' . $detector->eventName(), $detector->description() !== '');
    }
    $keys = [];
    foreach ($list as $detector) {
        $keys[] = $detector->payloadKeys();
    }
    check('catalog: payloadKeys identical for all',
        count(array_unique(array_map('json_encode', $keys))) === 1
        && $keys[0] === ['log_id', 'log_time', 'log_message', 'gedcom_id', 'user_id']);

    // --- LogRowDetector::truncateMessage ---------------------------------------------
    check('truncate: short message untouched', LogRowDetector::truncateMessage('abc', 250) === 'abc');
    check('truncate: exactly max untouched', LogRowDetector::truncateMessage(str_repeat('x', 250), 250) === str_repeat('x', 250));
    check('truncate: long ascii cut to max', strlen(LogRowDetector::truncateMessage(str_repeat('x', 300), 250)) === 250);
    // 250 bytes + 1: the cut splits the 2-byte sequence (C3 A4) after its lead byte.
    $two = str_repeat('a', 249) . "\xC3\xA4";
    check('truncate: split 2-byte sequence dropped', LogRowDetector::truncateMessage($two, 250) === str_repeat('a', 249));
    // 251 bytes: the cut splits the 3-byte sequence (E4 B8 AD) after lead + 1 continuation.
    $three = str_repeat('a', 248) . "\xE4\xB8\xAD";
    check('truncate: split 3-byte sequence dropped', LogRowDetector::truncateMessage($three, 250) === str_repeat('a', 248));
    // 251 bytes: the cut splits the 4-byte sequence (F0 9F 98 80) after lead + 2 continuations.
    $four = str_repeat('a', 247) . "\xF0\x9F\x98\x80";
    check('truncate: split 4-byte sequence dropped', LogRowDetector::truncateMessage($four, 250) === str_repeat('a', 247));
    // 252 bytes: a complete sequence at the boundary stays, the split one after it drops.
    $boundary = str_repeat('a', 248) . "\xC3\xA4\xC3\xBC";
    check('truncate: complete boundary sequence kept', LogRowDetector::truncateMessage($boundary, 250) === str_repeat('a', 248) . "\xC3\xA4");

    // --- LogRowDetector::compare -------------------------------------------------------
    $det = new LogRowDetector('cronjob:log-auth-failed', 'd', 'auth', ['Login failed']);
    $row = static function (int $id, string $msg, ?int $uid = 5, ?int $gid = 3): array {
        return ['log_id' => $id, 'log_time' => '2026-08-29 10:00:00', 'log_message' => $msg, 'user_id' => $uid, 'gedcom_id' => $gid];
    };
    $cur = static function (array $rows, int $max): array {
        return ['rows' => $rows, 'max_id' => $max];
    };

    $baseline = $det->compare(null, $cur([$row(1, 'Login failed (x): u')], 1));
    check('log: first run is baseline (no events)', $baseline['events'] === []);
    check('log: baseline state = max_id', $baseline['state'] === 1);

    $none = $det->compare(10, $cur([], 10));
    check('log: no new rows -> no events, state kept', $none['events'] === [] && $none['state'] === 10);

    $two_rows = $det->compare(10, $cur([
        $row(11, 'Login failed (incorrect password): bob'),
        $row(12, 'Login failed (no such user): ann'),
    ], 12));
    check('log: one event per matching row', count($two_rows['events']) === 2);
    check('log: payload key order', array_keys($two_rows['events'][0]) === ['log_id', 'log_time', 'log_message', 'gedcom_id', 'user_id']);
    check('log: payload values', $two_rows['events'][0] === [
        'log_id'      => 11,
        'log_time'    => '2026-08-29 10:00:00',
        'log_message' => 'Login failed (incorrect password): bob',
        'gedcom_id'   => 3,
        'user_id'     => 5,
    ]);
    check('log: caught up -> state = max_id', $two_rows['state'] === 12);

    $nulls = $det->compare(0, $cur([$row(1, 'Login failed (x): u', null, null)], 1));
    check('log: null gedcom_id/user_id omitted', $nulls['events'][0] === [
        'log_id'      => 1,
        'log_time'    => '2026-08-29 10:00:00',
        'log_message' => 'Login failed (x): u',
    ]);

    $prefix = $det->compare(10, $cur([
        $row(11, 'Login: bob/Bob'),
        $row(12, 'Login failed (x): u'),
    ], 12));
    check('log: non-matching prefix not fired', count($prefix['events']) === 1 && $prefix['events'][0]['log_id'] === 12);
    check('log: non-matching prefix, caught up -> state = max_id', $prefix['state'] === 12);

    $nomatch = $det->compare(10, $cur([$row(11, 'Login: bob/Bob')], 11));
    check('log: zero matches -> no events, state = max_id', $nomatch['events'] === [] && $nomatch['state'] === 11);

    $shrank = $det->compare(50, $cur([], 30));
    check('log: table shrank -> reset, no events', $shrank['events'] === [] && $shrank['state'] === 30);

    $many = [];
    for ($i = 1; $i <= 210; $i++) {
        $many[] = $row($i, 'Login failed (x): u' . $i);
    }
    $capped = $det->compare(0, $cur($many, 210));
    check('log: cap 200 events per run', count($capped['events']) === 200);
    check('log: cap -> checkpoint = last fired row (backpressure)', $capped['state'] === 200);
    $rest = $det->compare(200, $cur(array_slice($many, 200), 210));
    check('log: rest fired on the next run', count($rest['events']) === 10 && $rest['state'] === 210);

    $window = [];
    for ($i = 1; $i <= 500; $i++) {
        $window[] = $row($i, 'Login: bob/Bob' . $i);
    }
    $full_nomatch = $det->compare(0, $cur($window, 9999));
    check('log: full window, no match -> no events, checkpoint = window end',
        $full_nomatch['events'] === [] && $full_nomatch['state'] === 500);

    $window_match = [];
    for ($i = 1; $i <= 500; $i++) {
        $window_match[] = $row($i, 'Login failed (x): u' . $i);
    }
    $full_match = $det->compare(0, $cur($window_match, 9999));
    check('log: full window, all match -> 200 events, checkpoint = 200th',
        count($full_match['events']) === 200 && $full_match['state'] === 200);

    $err = new LogRowDetector('cronjob:log-error', 'd', 'error');
    $err_run = $err->compare(0, $cur([$row(1, 'Some exception trace')], 1));
    check('log: no-prefix detector fires any row of the type', count($err_run['events']) === 1 && $err_run['state'] === 1);

    // --- PseudoEventService::orphanedEvents (removed built-in detectors) --------------
    check('orphaned: known detector names kept',
        PseudoEventService::orphanedEvents(['cronjob:log-auth-failed', 'cronjob:log-error']) === []);
    check('orphaned: removed detector flagged',
        PseudoEventService::orphanedEvents(['cronjob:gedcom-changed']) === ['cronjob:gedcom-changed']);
    check('orphaned: foreign domains ignored',
        PseudoEventService::orphanedEvents(['_route:foo', 'linkenhancer:x']) === []);
    check('orphaned: mixed list',
        PseudoEventService::orphanedEvents(['cronjob:gedcom-changed', 'cronjob:log-search', '_route:bar', 'cronjob:media-added'])
        === ['cronjob:gedcom-changed', 'cronjob:media-added']);
    check('orphaned: empty input', PseudoEventService::orphanedEvents([]) === []);

    // --- PseudoEventService::pruneState (stale detector state after an update) --------
    check('pruneState: stale keys dropped',
        PseudoEventService::pruneState(['cronjob:gedcom-changed' => 1, 'cronjob:media-added' => 2, 'cronjob:log-auth-failed' => 5])
        === ['cronjob:log-auth-failed' => 5]);
    check('pruneState: empty stays empty', PseudoEventService::pruneState([]) === []);

    // --- ScheduleService::specDiff (§13: triggers_key) -----------------------
    $spec = [
        'name'         => 'pseudo-events',
        'title'        => 'T',
        'triggers'     => [
            ['type' => 'time', 'cron' => '*/5 * * * *', 'event' => ''],
        ],
        'command_type' => 'module',
        'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
        'args'         => '',
        'enabled'      => false,
        'timeout_sec'  => 120,
    ];
    $identical = [
        'title'        => 'T',
        'triggers_key' => ScheduleService::triggersKey($spec['triggers']),
        'command_type' => 'module',
        'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
        'args'         => '',
        'timeout_sec'  => 120,
    ];

    check('specDiff: identical -> no diff', ScheduleService::specDiff($identical, $spec) === []);

    $cron_changed = $identical;
    $cron_changed['triggers_key'] = ScheduleService::triggersKey([['type' => 'time', 'cron' => '*/10 * * * *', 'event' => '']]);
    check('specDiff: cron change -> [triggers_key]', ScheduleService::specDiff($cron_changed, $spec) === ['triggers_key']);

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

    $ev_spec = $spec;
    $ev_spec['triggers'] = [['type' => 'event', 'cron' => '', 'event' => 'cronjob:log-edit-update']];
    $ev_diff = ScheduleService::specDiff($identical, $ev_spec);
    check('specDiff: time->event job flags triggers_key only', $ev_diff === ['triggers_key']);

    // --- PseudoEventService file-based logic --------------------------------
    check('service: cooldown elapsed when no last file', PseudoEventService::cooldownElapsed() === true);
    touch($data . 'cronjob-pseudo-events-last');
    clearstatcache();
    check('service: cooldown active right after a run', PseudoEventService::cooldownElapsed() === false);
    touch($data . 'cronjob-pseudo-events-last', time() - 400);
    clearstatcache();
    check('service: cooldown elapsed after the interval', PseudoEventService::cooldownElapsed() === true);

    check('service: loadState default when no file', PseudoEventService::loadState() === ['last_run_at' => 0, 'detectors' => []]);
    PseudoEventService::saveState(['last_run_at' => 12345, 'detectors' => ['cronjob:log-auth-failed' => 5, 'cronjob:log-error' => 7]]);
    $loaded = PseudoEventService::loadState();
    check('service: state round-trip', $loaded === ['last_run_at' => 12345, 'detectors' => ['cronjob:log-auth-failed' => 5, 'cronjob:log-error' => 7]]);

    // Cleanup
    foreach (glob($data . 'cronjob-pseudo-events*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($data);
    @rmdir($base);

    echo $failures === 0 ? "All pseudo-events tests passed.\n" : "{$failures} test(s) FAILED.\n";
    exit($failures === 0 ? 0 : 1);
}
