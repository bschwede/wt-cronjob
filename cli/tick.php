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

// The once-a-minute trigger of the cronjob module.
//
// Run (from the webtrees root, with the same PHP version as the instance):
//   php modules_v4/cronjob/cli/tick.php [--dry-run] [--job=<name>] [--strict] [--full-output]
//
// Note: stdout carries summary lines only by default. Add --full-output for
// debugging when you are sure the log destination is not world-readable -
// job output may contain personal data (user-list) or site settings.
//
// What it does:
//   - skips entirely while the site is offline (data/offline.txt) - e.g.
//     during a webtrees update, when schema migrations and code are in flux
//   - finds the jobs whose next_run_at has passed and runs them one by one
//     as isolated child processes (per-job timeout, captured output)
//   - records every run in the cj_run table (visible in the admin UI)
//
// System trigger (pick one, once):
//   cron:    * * * * * cd /path/to/webtrees && mkdir -p data/cronjob && php modules_v4/cronjob/cli/tick.php >> /path/to/webtrees/data/cronjob/tick.log 2>&1
//   systemd: see the generated units in the module's admin page
//
// Conventions: see README.md ("CLI scripts & maintenance").

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\TickCommand;
use Symfony\Component\Console\Application;

CliBootstrap::guard();
CliBootstrap::exitOnsiteOffline();
CliBootstrap::boot();

$application = new Application('cronjob tick', '1.0.0');
$application->add(new TickCommand());
$application->run();
