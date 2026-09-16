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

use Fisharebest\Webtrees\Webtrees;

use function is_dir;
use function mkdir;

/**
 * The module's control files, gathered in data/cronjob/.
 *
 * webtrees' data/ directory is shared with the core and other modules, so
 * every file this module creates there (locks, state, logs, opt-in markers)
 * lives in its own subdirectory. Single source of truth for those paths:
 *
 *   data/cronjob/watch.enabled      watch opt-in marker
 *   data/cronjob/watch.lock         watch daemon liveness
 *   data/cronjob/watch.heartbeat    watch daemon mtime
 *   data/cronjob/watch-spawn        watch respawn cooldown
 *   data/cronjob/watch.log          watch supervisor log
 *   data/cronjob/tick.lock          tick single-instance lock
 *   data/cronjob/tick.last          last tick execution (mtime, watch + OS trigger)
 *   data/cronjob/pseudo-events.json pseudo-event detector state
 *   data/cronjob/pseudo-events.lock pseudo-event serialization
 *   data/cronjob/pseudo-events-last pseudo-event cooldown marker
 *   data/cronjob/tick.log           tick stdout (created by the cron redirect)
 *   data/cronjob/php-binary         optional manual PHP CLI path (admin-set)
 *
 * Files created before the subdirectory existed (flat data/cronjob-*) are
 * ignored (clean break) and may be deleted.
 */
final class DataFiles {

    /**
     * The module's data subdirectory (data/cronjob/), created on demand.
     */
    public static function dir(): string {
        $dir = Webtrees::DATA_DIR . 'cronjob/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * Absolute path of one control file in data/cronjob/.
     */
    public static function path(string $name): string {
        return self::dir() . $name;
    }
}
