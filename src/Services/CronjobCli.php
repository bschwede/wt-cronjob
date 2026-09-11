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

use function fclose;
use function fopen;
use function flock;
use function is_file;
use function preg_replace;
use function realpath;
use function str_contains;
use function str_starts_with;

/**
 * cronjob-specific CLI helpers - the parts of a CLI bootstrap that live
 * outside the shared Schwendinger\Webtrees\Services\CliBootstrap: the
 * non-blocking tick lock and the W1 payload-path confinement.
 */
final class CronjobCli {

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
