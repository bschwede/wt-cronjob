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
 * cronjob's own job manifest (self-registration, §6.3).
 *
 * It offers the pseudo-events polling job to itself - dogfooding the manifest
 * mechanism and the forced own-manifest sync (on a module update the stored
 * `cronjob:pseudo-events` job is re-synced to these values, keeping the
 * admin's enabled/notify/name and the run history). Discovered as
 * `cronjob:pseudo-events`; starts disabled (opt-in, like every offered job).
 *
 * Pure data - the JobSpec shape, interpreted by cronjob's validator.
 */

return [
    [
        'name'         => 'pseudo-events',
        'title'        => 'Cronjob: Pseudo-Events (state-poll detectors)',
        'trigger_type' => 'time',
        'cron'         => '*/5 * * * *',
        'command_type' => 'module',
        'command'      => 'modules_v4/cronjob/cli/pseudo-events.php',
        'args'         => '',
        'timeout_sec'  => 120,
        'enabled'      => false,
    ],
];
