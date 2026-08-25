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

// The resident "watch" daemon of the cronjob module (optional - use this OR
// an OS cron/systemd trigger, not both).
//
// Instead of an OS timer calling tick.php once a minute, this long-lived
// process ticks every 60 seconds on its own. It is the preferred trigger on
// hosts without cron (some containers / managed hosting).
//
// It is normally started from the module's admin page ("Start watch"), which
// also installs a page-load watchdog that respawns it automatically if it
// dies. Run by hand (rarely needed):
//   php modules_v4/cronjob/cli/watch.php
//
// Behaviour:
//   - refuses to start when another daemon already holds the watch lock
//   - runs the tick (cli/tick.php) as an isolated child once a minute; the
//     child does its own guard, offline check, boot and tick lock
//   - stays idle (no tick) while the site is offline (data/offline.txt)
//   - exits within one loop when the admin stops it or the module is
//     re-deployed (a changed fingerprint), so a fresh daemon picks up new code

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\WatchService;

CliBootstrap::guard();

// Core autoloader first - makes Webtrees::DATA_DIR / ROOT_DIR available for
// the state-file paths WITHOUT connecting to the database.
CliBootstrap::autoload();

WatchService::loop();

exit(0);
