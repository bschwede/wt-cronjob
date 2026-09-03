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

// Standalone test for the database-free logic of WatchService: the opt-in
// marker, the liveness-lock inversion, the respawn cooldown and the code
// fingerprint. No webtrees/DB needed - a minimal Webtrees constant stub
// stands in for the core class, and all state files live in a temp dir that
// the test creates and removes.
//
// Run: php modules_v4/cronjob/tests/test-watch-service.php

namespace Fisharebest\Webtrees {
    // Stand-in for the core class (not loaded by the module autoloader).
    if (!class_exists('Fisharebest\\Webtrees\\Webtrees', false)) {
        class Webtrees {
            public const DATA_DIR = __DIR__ . '/.watchtest/data/';
            public const ROOT_DIR = __DIR__ . '/.watchtest/';
        }
    }
}

namespace {

    require __DIR__ . '/../autoload.php';

    use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;
    use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;

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

    $base = __DIR__ . '/.watchtest';
    $data = $base . '/data/';
    @mkdir($data . 'cronjob/', 0777, true);
    foreach (glob($data . 'cronjob/*') ?: [] as $file) {
        @unlink($file);
    }

    // 1. Fresh state
    check('disabled by default', WatchService::featureEnabled() === false);
    check('no daemon running', WatchService::daemonAlive() === false);
    check('cooldown elapsed when no spawn file', WatchService::spawnCooldownElapsed() === true);

    // 2. Enable / disable
    WatchService::enable();
    check('enabled after enable()', WatchService::featureEnabled() === true);

    $status = WatchService::status();
    check('status reports enabled', $status['enabled'] === true);
    check('status reports not running', $status['running'] === false);
    check('status last_tick is 0', $status['last_tick'] === 0);

    // 3. Liveness inversion: holding the lock means a daemon is alive.
    $lock = fopen($data . 'cronjob/watch.lock', 'c');
    flock($lock, LOCK_EX);
    check('daemon alive while lock is held', WatchService::daemonAlive() === true);
    flock($lock, LOCK_UN);
    fclose($lock);
    check('daemon not alive after lock released', WatchService::daemonAlive() === false);

    // 4. Respawn cooldown is active right after a spawn attempt.
    touch($data . 'cronjob/watch-spawn');
    check('cooldown active right after spawn', WatchService::spawnCooldownElapsed() === false);

    // 5. Fingerprint (private) is a non-empty, stable string.
    $method = new \ReflectionMethod(WatchService::class, 'installFingerprint');
    $fp1 = $method->invoke(null);
    $fp2 = $method->invoke(null);
    check('fingerprint is a non-empty string', is_string($fp1) && $fp1 !== '');
    check('fingerprint is stable', $fp1 === $fp2);

    // 6. Disable
    WatchService::disable();
    check('disabled after disable()', WatchService::featureEnabled() === false);

    // 7. PHP-CLI resolution - the browser "Start watch" / watchdog path runs
    //    under php-fpm where PHP_BINARY is empty or the server binary.
    $rm_is   = new \ReflectionMethod(WatchService::class, 'isCliPhp');
    $fakes   = $base . '/fakes';
    @mkdir($fakes . '/noexec', 0777, true);
    file_put_contents($fakes . '/php', "#!/bin/sh\n");
    chmod($fakes . '/php', 0755);
    file_put_contents($fakes . '/php-fpm8.3', "#!/bin/sh\n");
    chmod($fakes . '/php-fpm8.3', 0755);
    file_put_contents($fakes . '/noexec/php', "#!/bin/sh\n");
    chmod($fakes . '/noexec/php', 0644);

    check('isCliPhp: executable php accepted', $rm_is->invoke(null, $fakes . '/php') === true);
    check('isCliPhp: php-fpm binary rejected', $rm_is->invoke(null, $fakes . '/php-fpm8.3') === false);
    check('isCliPhp: non-executable rejected', $rm_is->invoke(null, $fakes . '/noexec/php') === false);
    check('isCliPhp: nonexistent rejected', $rm_is->invoke(null, $fakes . '/nope') === false);
    check('isCliPhp: empty rejected', $rm_is->invoke(null, '') === false);

    $binary = WatchService::phpBinary();
    check('phpBinary() resolves a non-empty path', is_string($binary) && $binary !== '');
    check('phpBinary() result is a usable CLI php', $rm_is->invoke(null, $binary) === true);

    // 8. findCliPhp: the pure PATH-directory search (version-specific names
    //    tried per directory, fpm-named / non-executable binaries rejected).
    $rm_find = new \ReflectionMethod(WatchService::class, 'findCliPhp');
    check('findCliPhp: finds the fake php', $rm_find->invoke(null, [$fakes], ['php']) === $fakes . '/php');
    check('findCliPhp: rejects fpm-named binary', $rm_find->invoke(null, [$fakes], ['php-fpm8.3']) === '');
    check('findCliPhp: empty when nothing found', $rm_find->invoke(null, [$fakes . '/nope', ''], ['php']) === '');

    // 9. triggerInstallBlocks: the Windows Task Scheduler XML is structural.
    $blocks = \Schwendinger\Webtrees\Module\Cronjob\CronjobUtils::triggerInstallBlocks();
    check('install blocks: windows keys present', isset($blocks['windows_task_xml'], $blocks['windows_import']));
    $xml = (string) $blocks['windows_task_xml'];
    check('win xml: task root + namespace', str_contains($xml, '<Task version="1.2"') && str_contains($xml, 'schemas.microsoft.com/windows/2004/02/mit/task'));
    check('win xml: repeat every minute', str_contains($xml, '<Interval>PT1M</Interval>'));
    check('win xml: ignore new instances', str_contains($xml, '<MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>'));
    check('win xml: no scheduler time limit', str_contains($xml, '<ExecutionTimeLimit>PT0H</ExecutionTimeLimit>'));
    check('win xml: cmd wrapper with mkdir self-heal', str_contains($xml, '<Command>cmd.exe</Command>') && str_contains($xml, 'if not exist data\cronjob mkdir data\cronjob'));
    check('win xml: escaped redirect to tick.log', str_contains($xml, '&gt;&gt; data\cronjob\tick.log 2&gt;&amp;1'));
    check('win xml: tick script in args', str_contains($xml, 'modules_v4\\cronjob\\cli\\tick.php cron:tick'));
    check('win xml: start boundary is ISO', (bool) preg_match('/<StartBoundary>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}<\/StartBoundary>/', $xml));
    check('win import: schtasks /Create /F /XML', str_contains($blocks['windows_import'], 'schtasks /Create /F /XML wt-cronjob-tick.xml'));

    // 9b. Install blocks quote the interpolated paths (spaces-safe).
    check('install blocks: cron line quotes the php binary', str_contains($blocks['cron_line'], '"' . $binary . '"'));
    check('install blocks: cron line quotes the root', str_contains($blocks['cron_line'], 'cd "'));
    check('install blocks: cron line quotes the log target', str_contains($blocks['cron_line'], '>> "' ));
    check('install blocks: systemd ExecStart quotes the php binary', str_contains($blocks['service_unit'], 'ExecStart="' . $binary . '"'));
    check('install blocks: systemd WorkingDirectory quoted', str_contains($blocks['service_unit'], 'WorkingDirectory="' ));
    check('win xml: cmd /S with quoted root', str_contains($xml, '/S /c "cd /d "'));

    // 10. Manual PHP binary override (data/cronjob/php-binary).
    $info = WatchService::phpBinaryInfo();
    check('php info: auto by default', $info['source'] === 'auto' && $info['configured'] === '' && $info['binary'] === $binary);

    WatchService::setManualPhp($fakes . '/php');
    check('manual php: phpBinary() honors the manual path', WatchService::phpBinary() === $fakes . '/php');
    $info = WatchService::phpBinaryInfo();
    check('manual php: info reports the manual source', $info['source'] === 'manual' && $info['manual_valid'] === true && $info['configured'] === $fakes . '/php' && $info['binary'] === $fakes . '/php');

    WatchService::setManualPhp($fakes . '/php-fpm8.3');
    $info = WatchService::phpBinaryInfo();
    check('manual php: fpm-named path falls back to auto', $info['manual_valid'] === false && $info['source'] === 'auto' && $info['binary'] === $binary && $info['configured'] === $fakes . '/php-fpm8.3');

    WatchService::setManualPhp('/no/such/php');
    $info = WatchService::phpBinaryInfo();
    check('manual php: nonexistent path falls back to auto', $info['manual_valid'] === false && $info['source'] === 'auto' && $info['binary'] === $binary);

    WatchService::setManualPhp($fakes . '/noexec/php');
    $info = WatchService::phpBinaryInfo();
    check('manual php: non-executable path falls back to auto', $info['manual_valid'] === false && $info['source'] === 'auto');

    WatchService::setManualPhp('');
    $info = WatchService::phpBinaryInfo();
    check('manual php: cleared again', $info['source'] === 'auto' && $info['configured'] === '' && WatchService::phpBinary() === $binary);

    // 11. daemonPid(): the informational PID of the running daemon.
    check('daemon pid: null when no daemon runs', WatchService::daemonPid() === null);

    $lock = fopen($data . 'cronjob/watch.lock', 'c');
    flock($lock, LOCK_EX);
    fseek($lock, 0);
    ftruncate($lock, 0);
    fwrite($lock, (string) getmypid());
    check('daemon pid: reports the lock holder pid', WatchService::daemonPid() === getmypid());

    fseek($lock, 0);
    ftruncate($lock, 0);
    fwrite($lock, '999999999');
    check('daemon pid: stale pid reported as null', WatchService::daemonPid() === null);

    flock($lock, LOCK_UN);
    fclose($lock);
    check('daemon pid: null again after release', WatchService::daemonPid() === null);
    check('status carries the pid key', array_key_exists('pid', WatchService::status()));

    // 12. nextRunForRepair(): the self-heal decision (pure).
    check('repair: event-only job stays null', ScheduleService::nextRunForRepair([['type' => 'event', 'cron' => '', 'event' => 'x']], '2026-01-01 00:00:00') === null);
    $repair = ScheduleService::nextRunForRepair([['type' => 'time', 'cron' => '*/10 * * * *', 'event' => '']], '2026-01-01 00:00:00');
    check('repair: time job gets a next run', is_string($repair) && $repair > '2026-01-01 00:00:00');
    $mixed = ScheduleService::nextRunForRepair([['type' => 'event', 'cron' => '', 'event' => 'x'], ['type' => 'time', 'cron' => '@hourly', 'event' => '']], '2026-01-01 00:00:00');
    check('repair: mixed triggers get a next run', is_string($mixed) && $mixed > '2026-01-01 00:00:00');

    // Cleanup
    foreach (glob($data . 'cronjob/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($fakes . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($fakes . '/noexec/php');
    @rmdir($fakes . '/noexec');
    @rmdir($fakes);
    @rmdir($data . 'cronjob/');
    @rmdir($data);
    @rmdir($base);

    if ($failures === 0) {
        echo "All watch-service tests passed.\n";
    } else {
        echo "{$failures} test(s) FAILED.\n";
    }
    exit($failures === 0 ? 0 : 1);
}
