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

// W1 CLI wrapper: generic bootstrap + runner for OTHER modules' cron payloads.
//
// This file is REQUIREd by a 3-line module stub, e.g.
//     modules_v4/<module>/cli/foo.php
//         require __DIR__ . '/../../cronjob/cli/wrap.php';
// It is never itself a job command. After bootstrapping webtrees + the
// database it includes the payload: the sibling of the invoking stub with the
// extension swapped from '.php' to '.logic.php' (foo.php -> foo.logic.php).
//
// The payload path is DERIVED from the entry script (SCRIPT_FILENAME), never
// taken from an argument, so there is no attacker-controlled include path.
// The confinement (realpath under modules_v4/*/cli/) is enforced in
// CronjobCli::resolvePayloadPath(). The payload itself carries a 1-line
// SAPI guard, since it is a .php file inside cli/ (URL-reachable).
//
// Requires the cronjob module to be installed. See README.md, "Offering jobs
// to cronjob" and "CLI scripts & maintenance".

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\CronjobCli;

CliBootstrap::guard();
CliBootstrap::exitOnsiteOffline();
CliBootstrap::boot();

$modules = realpath(__DIR__ . '/../../');
if ($modules === false) {
    fwrite(STDERR, 'cannot resolve modules_v4/ directory' . PHP_EOL);
    exit(1);
}

$entry   = $_SERVER['SCRIPT_FILENAME'] ?? '';
$payload = $entry === '' ? null : CronjobCli::resolvePayloadPath($entry, $modules);

if ($payload === null) {
    fwrite(STDERR, 'no confined W1 payload found for entry: ' . $entry . PHP_EOL);
    exit(1);
}

require $payload;
