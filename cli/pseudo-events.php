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

// Poll the built-in pseudo-event detectors (GEDCOM change, new media, new
// user) and queue any detected transitions for the tick's event drain.
//
// This is cronjob's own manifest job (`cronjob:pseudo-events`). It is offered
// by modules_v4/cronjob/cron-jobs.php and is the trigger for the polling
// detectors - it runs in the tick's child process (isolated from any web
// request, bounded by the job's timeout). It is created disabled; enabling the
// job turns detection on.
//
// Run (from the webtrees root):
//   php modules_v4/cronjob/cli/pseudo-events.php [--force]
//
//   --force  bypass the 5-minute cooldown (a manual run)
//
// The runner is a no-op unless at least one enabled event-triggered job is
// listening.

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents\PseudoEventService;

CliBootstrap::guard();

// Core autoloader first - makes Webtrees::DATA_DIR available for the offline
// check WITHOUT touching the database.
CliBootstrap::autoload();

if (CliBootstrap::siteIsOffline()) {
    echo 'site offline (data/offline.txt) - pseudo-events skipped' . PHP_EOL;
    exit(0);
}

CliBootstrap::boot();

$force  = in_array('--force', $argv ?? [], true);
$pushed = PseudoEventService::run($force);

echo 'pseudo-events: ' . $pushed . ' event(s) queued' . PHP_EOL;
exit(0);
