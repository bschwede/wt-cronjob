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
 * Upgrade the database schema from version 1 to version 2 (phase 2, §6.1/§6.2).
 *
 * Adds to cj_job:
 *  - `event_name` — the event an event-triggered job reacts to (NULL for time jobs)
 *  - `notify`     — notify the site administrators when the job fails
 *
 * Creates the event queue:
 *  - `cj_event` — pending events pushed by the webhook or by module code
 */
class Migration1 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // cj_job: phase-2 columns (event trigger + failure notification)
        // =========================================================================
        if (DB::schema()->hasTable('cj_job')) {
            if (!DB::schema()->hasColumn('cj_job', 'event_name')) {
                DB::schema()->table('cj_job', function (Blueprint $table): void {
                    // NULL for time-based jobs; a slug for trigger_type='event' jobs.
                    $table->string('event_name', 64)->nullable()->default(null)->after('trigger_type');
                });
            }
            if (!DB::schema()->hasColumn('cj_job', 'notify')) {
                DB::schema()->table('cj_job', function (Blueprint $table): void {
                    $table->boolean('notify')->default(false)->after('timeout_sec');
                });
            }
        }

        // =========================================================================
        // Table 3: cj_event (event queue)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_event')) {
            DB::schema()->create('cj_event', function (Blueprint $table): void {
                $table->bigInteger('id', true);
                // slug, e.g. 'index-dirty', 'gedcom-imported'
                $table->string('event_name', 64);
                // JSON-encoded payload (arbitrary, producer-defined)
                $table->text('payload')->nullable()->default(null);
                $table->timestamp('created_at', 0)->useCurrent();
                // Set when a run has consumed this event (retention / audit).
                $table->integer('handled_run_id')->nullable()->default(null);
                $table->index(['event_name', 'handled_run_id']);
            });
        }
    }
}
