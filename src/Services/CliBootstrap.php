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

namespace Schwendinger\Webtrees\Module\Cronjob\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use Throwable;

use function file_exists;
use function fclose;
use function fopen;
use function flock;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function parse_ini_file;
use function preg_replace;
use function realpath;
use function str_contains;
use function str_starts_with;

/**
 * Shared bootstrap for this module's CLI scripts.
 *
 * modules_v4/ lives inside the web root, so every script in there is
 * reachable by URL. Each script MUST start with guard() before any
 * module or webtrees code.
 *
 * The offline check (siteIsOffline()) runs BEFORE boot(), i.e. before
 * any database access: while webtrees is offline (typically an update
 * in progress - schema migrations + code swap) no child job may start.
 */
final class CliBootstrap {

    /** webtrees root: src/Services is four levels below it */
    public const WEBTREES_ROOT = __DIR__ . '/../../../../';

    /**
     * Aborts when the script is not running from the command line.
     * Without this guard a script called by URL would be a data leak.
     */
    public static function guard(): void {
        if (PHP_SAPI !== 'cli') {
            http_response_code(403);
            exit('CLI only.');
        }
    }

    /**
     * Loads only the webtrees (core) vendor autoloader. Needed to make
     * the Webtrees class constants available for the offline check
     * WITHOUT connecting to the database.
     */
    public static function autoload(): void {
        require_once self::WEBTREES_ROOT . '/vendor/autoload.php';
    }

    /**
     * Site offline flag, 1:1 semantics of the core
     * MaintenanceModeService::isOffline()
     * (app/Services/MaintenanceModeService.php).
     */
    public static function siteIsOffline(): bool {
        $file = Webtrees::DATA_DIR . 'offline.txt';

        return is_file($file) || is_link($file) || is_dir($file);
    }

    /**
     * Reproduces the core CLI initialization (see app/Webtrees.php:243-260
     * and app/Cli/Console.php:64-89):
     *
     *   vendor/autoload.php -> Webtrees::new()->bootstrap()
     *   -> I18N::init('en-US', setup: true)
     *   -> parse_ini_file(Webtrees::CONFIG_FILE) -> DB::connect(…)
     *
     * Deliberate difference to the core: a missing config file or a failed
     * database connection aborts with a clear message and exit code 1.
     *
     * @return array<string,string> the parsed contents of Webtrees::CONFIG_FILE
     */
    public static function boot(): array {
        require_once self::WEBTREES_ROOT . '/vendor/autoload.php';

        Webtrees::new()->bootstrap();
        I18N::init(code: 'en-US', setup: true);

        if (!file_exists(Webtrees::CONFIG_FILE)) {
            fwrite(STDERR, 'No config file found: ' . Webtrees::CONFIG_FILE . "\n");
            exit(1);
        }

        $config = parse_ini_file(Webtrees::CONFIG_FILE) ?: [];
        if ($config === []) {
            fwrite(STDERR, 'Empty or unreadable config file: ' . Webtrees::CONFIG_FILE . "\n");
            exit(1);
        }

        try {
            DB::connect(
                driver: $config['dbtype'] ?? DB::MYSQL,
                host: $config['dbhost'] ?? '',
                port: $config['dbport'] ?? '',
                database: $config['dbname'] ?? '',
                username: $config['dbuser'] ?? '',
                password: $config['dbpass'] ?? '',
                prefix: $config['tblpfx'] ?? '',
                key: $config['dbkey'] ?? '',
                certificate: $config['dbcert'] ?? '',
                ca: $config['dbca'] ?? '',
                verify_certificate: (bool) ($config['dbverify'] ?? ''),
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Database connection failed: ' . $exception->getMessage() . "\n");
            exit(1);
        }

        return $config;
    }

    /**
     * Acquire the tick lock (non-blocking).
     *
     * @return resource|null the open handle, or null when another tick
     *                       is already running
     */
    public static function acquireTickLock() {
        $lock_file = DataFiles::path('tick.lock');
        $lock      = fopen($lock_file, 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        return $lock;
    }

    /**
     * @param resource|null $lock
     */
    public static function releaseTickLock($lock): void {
        if ($lock !== null) {
            fclose($lock);
        }
    }

    /**
     * Resolve the W1 payload for a given entry script, confined to
     * <modules_dir>/<module>/cli/. The payload is the sibling file whose name is the
     * entry's with the trailing '.php' swapped for '.logic.php' - DERIVED from
     * the entry script, never taken from an argument (no attacker-controlled
     * include path, which is the core W1 safety property).
     *
     * @return string|null the realpath'd payload, or null (fail closed)
     */
    public static function resolvePayloadPath(string $entry, string $modules_dir): ?string {
        $logic = preg_replace('/\.php$/', '.logic.php', $entry) ?? $entry;
        $real  = is_file($logic) ? realpath($logic) : false;
        $base  = realpath($modules_dir);

        if ($real === false || $base === false || !is_file($real)) {
            return null;
        }
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!str_contains($real, DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
