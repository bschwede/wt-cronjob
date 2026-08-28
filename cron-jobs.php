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

/**
 * cronjob's own job manifest (self-registration, §6.3 / §12).
 *
 * It offers the pseudo-events polling job to itself - dogfooding the manifest
 * mechanism and the forced own-manifest sync (on a module update the stored
 * `cronjob:pseudo-events` job is re-synced to these values, keeping the
 * admin's enabled/notify/name and the run history). Discovered as
 * `cronjob:pseudo-events`; starts disabled (opt-in, like every offered job).
 *
 * It also announces its runnable CLI command(s) and their available
 * parameters (§12 command catalog) - here just pseudo-events.php. By
 * announcing, cronjob takes over curation of its own commands: the glob-based
 * discovery no longer lists its internal scripts (tick/watch/wrap),
 * which must not be invoked directly as jobs.
 *
 * Pure data - the JobSpec / command-spec shapes, interpreted by cronjob's
 * validators.
 */

return [
    'jobs' => [
        [
            'name'         => 'pseudo-events',
            'title'        => 'Cronjob: Pseudo-Events (state-poll detectors)',
            'triggers'     => [
                ['type' => 'time', 'cron' => '*/5 * * * *'],
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
            'args'         => '',
            'timeout_sec'  => 120,
            'enabled'      => false,
        ],
    ],
    'commands' => [
        [
            'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
            'command_type' => 'module',
            'description'  => 'Poll the built-in pseudo-event detectors (GEDCOM change, new media, new user) and queue any detected transitions for the tick.',
            'params'       => [
                [
                    'name'        => '--force',
                    'optional'    => true,
                    'description' => 'Bypass the 5-minute cooldown (a manual run).',
                ],
            ],
        ],
        [
            'command'       => 'modules_v4/cronjob/cli/smoke-job.php',
            'command_type'  => 'module',
            'description'   => 'Acceptance-test job for the cronjob module. it only prints and exits 0.',
            'params'        => [
                [
                    'name' => '--sleep=N',
                    'optional' => true,
                    'description' => 'keeps the process alive for N seconds (timeout testing)',
                ],
            ],
        ],        
    ],
];
