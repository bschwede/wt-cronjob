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
 * Upgrade the database schema from version 5 to version 6 (§13).
 *
 * Moves job triggers from the single-trigger cj_job columns
 * (trigger_type / cron / event_name) into a cross table so one job can be
 * fired by several triggers (N time + M event):
 *
 *  1. Creates `cj_job_trigger` (job_id FK, trigger_type, cron, event_name).
 *  2. Data migration: every existing cj_job row yields exactly one trigger
 *     row (time jobs -> their cron, event jobs -> their event name).
 *  3. `cj_run` gains `trigger_detail` (which cron / event name fired a run).
 *  4. Drops the now-redundant cj_job columns (clean break, like §11).
 *
 * cj_job.next_run_at stays at job level (the minimum over all time
 * triggers); dueJobs() and "run now" keep working unchanged.
 */
class Migration5 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // Table 7: cj_job_trigger (multi-trigger cross table)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_job_trigger')) {
            DB::schema()->create('cj_job_trigger', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('job_id');
                // 'time' (cron) | 'event' (event name)
                $table->string('trigger_type', 16)->default('time');
                // Set for time triggers, NULL for event triggers.
                $table->string('cron', 64)->nullable()->default(null);
                // Set for event triggers, NULL for time triggers.
                $table->string('event_name', 64)->nullable()->default(null);
                $table->unique(['job_id', 'trigger_type', 'cron', 'event_name']);
                $table->index(['event_name']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('cj_job')
                    ->onDelete('cascade');
            });
        }

        // =========================================================================
        // Data: one trigger row per existing job (before the columns drop)
        // =========================================================================
        if (
            DB::schema()->hasTable('cj_job')
            && DB::schema()->hasColumn('cj_job', 'trigger_type')
            && DB::schema()->hasColumn('cj_job', 'cron')
        ) {
            foreach (DB::table('cj_job')->get(['id', 'trigger_type', 'cron', 'event_name']) as $job) {
                $trigger_type = (string) $job->trigger_type;
                if ($trigger_type === 'event') {
                    $event_name = (string) ($job->event_name ?? '');
                    if ($event_name !== '') {
                        DB::table('cj_job_trigger')->insert([
                            'job_id'       => (int) $job->id,
                            'trigger_type' => 'event',
                            'event_name'   => $event_name,
                        ]);
                    }
                    // A legacy event job without a name has no usable trigger;
                    // the admin must re-save the job (UI shows a warning badge).
                } else {
                    $cron = (string) $job->cron;
                    if ($cron !== '') {
                        DB::table('cj_job_trigger')->insert([
                            'job_id'       => (int) $job->id,
                            'trigger_type' => 'time',
                            'cron'         => $cron,
                        ]);
                    }
                }
            }
        }

        // =========================================================================
        // cj_run: which trigger fired a run (cron expression / event name)
        // =========================================================================
        if (DB::schema()->hasTable('cj_run') && !DB::schema()->hasColumn('cj_run', 'trigger_detail')) {
            DB::schema()->table('cj_run', function (Blueprint $table): void {
                $table->string('trigger_detail', 255)->nullable()->default(null)->after('trigger');
            });
        }

        // =========================================================================
        // cj_job: drop the superseded single-trigger columns (clean break)
        // =========================================================================
        if (DB::schema()->hasTable('cj_job')) {
            foreach (['trigger_type', 'event_name', 'cron'] as $column) {
                if (DB::schema()->hasColumn('cj_job', $column)) {
                    DB::schema()->table('cj_job', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
}
