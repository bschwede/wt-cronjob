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

namespace Schwendinger\Webtrees\Module\Cronjob\Schema;

use Fisharebest\Webtrees\Schema\MigrationInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

/**
 * Upgrade the database schema from version 2 to version 3 (§11).
 *
 * 1. Renames the built-in event names to the namespaced scheme (clean
 *    break - only the exact old names are touched, rows that already
 *    carry a colon are left alone, so this is idempotent):
 *      gedcom-changed   -> cronjob:gedcom-changed
 *      media-added      -> cronjob:media-added
 *      user-registered  -> cronjob:user-registered
 *      edit-note-object -> _route:edit-note-object
 *    in both cj_job.event_name and cj_event.event_name.
 *
 * 2. Creates the event/run cross table:
 *    - `cj_event_run` — many-to-many audit: which runs consumed which events
 *      (cj_event.handled_run_id only stores the FIRST consuming run)
 */
class Migration2 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // Data: namespaced event names
        // =========================================================================
        $renames = [
            'gedcom-changed'   => 'cronjob:gedcom-changed',
            'media-added'      => 'cronjob:media-added',
            'user-registered'  => 'cronjob:user-registered',
            'edit-note-object' => '_route:edit-note-object',
        ];
        foreach (['cj_job', 'cj_event'] as $table) {
            if (!DB::schema()->hasTable($table)) {
                continue;
            }
            foreach ($renames as $old => $new) {
                DB::table($table)->where('event_name', '=', $old)->update(['event_name' => $new]);
            }
        }

        // =========================================================================
        // Table 4: cj_event_run (event/run cross table)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_event_run')) {
            DB::schema()->create('cj_event_run', function (Blueprint $table): void {
                $table->bigInteger('event_id');
                $table->bigInteger('run_id');
                $table->unique(['event_id', 'run_id']);
                $table->foreign('event_id')
                    ->references('id')
                    ->on('cj_event')
                    ->onDelete('cascade');
                $table->foreign('run_id')
                    ->references('id')
                    ->on('cj_run')
                    ->onDelete('cascade');
            });
        }
    }
}
