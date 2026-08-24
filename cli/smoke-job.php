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

// Acceptance-test job for the cronjob module. No webtrees/DB bootstrap
// needed - it only prints and exits 0.
//
// Run:
//   php modules_v4/cronjob/cli/smoke-job.php [--sleep=N]
//
// --sleep=N keeps the process alive for N seconds (timeout testing).

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;

CliBootstrap::guard();

$sleep = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--sleep=')) {
        $sleep = max(0, (int) substr($arg, 8));
    }
}

echo 'smoke-job ok - ' . date('Y-m-d H:i:s') . ' (pid ' . getmypid() . ')' . PHP_EOL;

if ($sleep > 0) {
    sleep($sleep);
    echo 'smoke-job slept ' . $sleep . ' s' . PHP_EOL;
}

exit(0);
