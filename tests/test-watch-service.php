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
    @mkdir($data, 0777, true);
    foreach (glob($data . 'cronjob-watch*') ?: [] as $file) {
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
    $lock = fopen($data . 'cronjob-watch.lock', 'c');
    flock($lock, LOCK_EX);
    check('daemon alive while lock is held', WatchService::daemonAlive() === true);
    flock($lock, LOCK_UN);
    fclose($lock);
    check('daemon not alive after lock released', WatchService::daemonAlive() === false);

    // 4. Respawn cooldown is active right after a spawn attempt.
    touch($data . 'cronjob-watch-spawn');
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

    // Cleanup
    foreach (glob($data . 'cronjob-watch*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($fakes . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($fakes . '/noexec/php');
    @rmdir($fakes . '/noexec');
    @rmdir($fakes);
    @rmdir($data);
    @rmdir($base);

    if ($failures === 0) {
        echo "All watch-service tests passed.\n";
    } else {
        echo "{$failures} test(s) FAILED.\n";
    }
    exit($failures === 0 ? 0 : 1);
}
