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
 * Upgrade the database schema from version 0 (no tables) to version 1.
 *
 * Creates the two tables required by the Cronjob module:
 * - `cj_job` — the job registry (schedule, command, state)
 * - `cj_run` — the run history (status, exit code, captured output)
 *
 * All timestamps are UTC (same basis as the webtrees core, which sets
 * date_default_timezone_set('UTC') in Webtrees::bootstrap()).
 */
class Migration0 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // Table 1: cj_job
        // =========================================================================
        if (!DB::schema()->hasTable('cj_job')) {
            DB::schema()->create('cj_job', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('name', 64)->unique()->default('');
                $table->string('title', 128)->default('');
                // v1: only 'time'; phase 2: 'event'
                $table->string('trigger_type', 16)->default('time');
                $table->string('cron', 64)->default('');
                // 'module' (modules_v4/*/cli/*.php) | 'core' (allowlisted index.php command)
                $table->string('command_type', 16)->default('module');
                $table->string('command', 255)->default('');
                $table->string('args', 255)->default('');
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('timeout_sec')->default(300);
                $table->timestamp('next_run_at', 0)->nullable()->default(null);
                $table->timestamp('last_run_at', 0)->nullable()->default(null);
                $table->smallInteger('last_exit', false)->nullable()->default(null);
                // ok | error | timeout | skipped
                $table->string('last_status', 16)->nullable()->default(null);
                $table->timestamp('created_at', 0)->useCurrent();
                $table->timestamp('updated_at', 0)->useCurrent();
            });
        }

        // =========================================================================
        // Table 2: cj_run
        // =========================================================================
        if (!DB::schema()->hasTable('cj_run')) {
            DB::schema()->create('cj_run', function (Blueprint $table): void {
                $table->bigInteger('id', true);
                $table->unsignedInteger('job_id');
                // schedule | manual (phase 2: event)
                $table->string('trigger', 16)->default('schedule');
                $table->timestamp('started_at', 0);
                $table->timestamp('finished_at', 0)->nullable()->default(null);
                $table->smallInteger('exit_code', false)->nullable()->default(null);
                // running | ok | error | timeout
                $table->string('status', 16)->default('running');
                $table->unsignedInteger('duration_ms')->nullable()->default(null);
                $table->mediumText('output')->nullable()->default(null);
                $table->index(['job_id', 'started_at']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('cj_job')
                    ->onDelete('cascade');
            });
        }
    }
}
