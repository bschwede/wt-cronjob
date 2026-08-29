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

use Schwendinger\Webtrees\Module\Cronjob\MoreI18N;
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
 * validators. Translatable literals are wrapped in MoreI18N::translate()
 * (identity marker for xgettext extraction; no translation at load time -
 * see src/MoreI18N.php).
 */

return [
    'jobs' => [
        [
            'name'         => 'pseudo-events',
            /* I18N: default title of the pseudo-events job (job list in the cronjob admin) */
            'title'        => MoreI18N::translate('Cronjob: Pseudo-Events (log-table pollers)'),
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
            /* I18N: command description for pseudo-events.php (command catalog in the cronjob admin) */
            'description'  => MoreI18N::translate('Poll the webtrees log table (failed logins, logins, logouts, errors, record edits, searches) and queue one event per new matching log entry for the tick.'),
            'params'       => [
                [
                    'name'        => '--force',
                    'optional'    => true,
                    /* I18N: parameter description for --force (command catalog in the cronjob admin) */
                    'description' => MoreI18N::translate('Bypass the 5-minute cooldown (a manual run).'),
                ],
            ],
        ],
        [
            'command'       => 'modules_v4/cronjob/cli/smoke-job.php',
            'command_type'  => 'module',
            /* I18N: command description for smoke-job.php (command catalog in the cronjob admin) */
            'description'   => MoreI18N::translate('Acceptance-test job for the cronjob module. It only prints and exits 0.'),
            'params'        => [
                [
                    'name' => '--sleep=N',
                    'optional' => true,
                    /* I18N: parameter description for --sleep=N (command catalog in the cronjob admin) */
                    'description' => MoreI18N::translate('keeps the process alive for N seconds (timeout testing)'),
                ],
            ],
        ],        
    ],
];
