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
 * Create the database schema of the Cronjob module (version 0 -> 1).
 *
 * The module is not released yet, so the former step-by-step migrations
 * (phase-2 columns, the event-name namespacing, the event/command catalogs
 * and the multi-trigger cross table) are squashed into this single
 * migration: it directly creates the current schema. The next schema
 * change becomes Migration1 again.
 *
 * Tables (created in FK-dependency order):
 *  - `cj_job`             the job registry (schedule, command, state)
 *  - `cj_run`             the run history (status, exit code, captured output)
 *  - `cj_event`           the event queue (webhook / pseudo / route events)
 *  - `cj_event_run`       event/run cross table (which runs consumed which events)
 *  - `cj_job_trigger`     multi-trigger cross table (N time + M event per job)
 *  - `cj_event_catalog`   event inventory (synced by the tick)
 *  - `cj_command_catalog` command inventory (synced by the tick)
 *
 * Idempotent (hasTable guards), safe to re-run. All timestamps are UTC
 * (same basis as the webtrees core, which sets
 * date_default_timezone_set('UTC') in Webtrees::bootstrap()).
 */
class Migration0 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // Table 1: cj_job (job registry)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_job')) {
            DB::schema()->create('cj_job', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('name', 64)->unique()->default('');
                $table->string('title', 128)->default('');
                // 'module' (modules_v4/*/cli/*.php) | 'core' (allowlisted index.php command)
                $table->string('command_type', 16)->default('module');
                $table->string('command', 255)->default('');
                $table->string('args', 255)->default('');
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('timeout_sec')->default(300);
                // Notify the site administrators when the job fails.
                $table->boolean('notify')->default(false);
                // Minimum over all time triggers (see ScheduleService::nextRunMin()).
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
        // Table 2: cj_run (run history)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_run')) {
            DB::schema()->create('cj_run', function (Blueprint $table): void {
                $table->bigInteger('id', true);
                $table->integer('job_id');
                // schedule | manual | event
                $table->string('trigger', 16)->default('schedule');
                // Which trigger fired a run (cron expression / event name).
                $table->string('trigger_detail', 255)->nullable()->default(null);
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

        // =========================================================================
        // Table 3: cj_event (event queue)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_event')) {
            DB::schema()->create('cj_event', function (Blueprint $table): void {
                $table->bigInteger('id', true);
                // slug or namespaced name, e.g. '_route:index-dirty', 'cronjob:log-error'
                $table->string('event_name', 64);
                // JSON-encoded payload (arbitrary, producer-defined)
                $table->text('payload')->nullable()->default(null);
                $table->timestamp('created_at', 0)->useCurrent();
                // Set when a run has consumed this event (retention / audit;
                // the full audit trail lives in cj_event_run).
                $table->integer('handled_run_id')->nullable()->default(null);
                $table->index(['event_name', 'handled_run_id']);
            });
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

        // =========================================================================
        // Table 5: cj_job_trigger (multi-trigger cross table)
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
        // Table 6: cj_event_catalog (event inventory)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_event_catalog')) {
            DB::schema()->create('cj_event_catalog', function (Blueprint $table): void {
                $table->string('event_name', 64)->primary();
                // cronjob (built-in) | _route | <module> | listening
                $table->string('source', 64)->default('');
                // Source string / translation key (never translated at sync time)
                $table->string('description', 255)->nullable()->default(null);
                // JSON list of payload parameter names (if known)
                $table->string('payload', 255)->nullable()->default(null);
                $table->timestamp('updated_at', 0)->useCurrent();
            });
        }

        // =========================================================================
        // Table 7: cj_command_catalog (command inventory)
        // =========================================================================
        if (!DB::schema()->hasTable('cj_command_catalog')) {
            DB::schema()->create('cj_command_catalog', function (Blueprint $table): void {
                // module script path (modules_v4/<mod>/cli/<script>.php) or a core command name
                $table->string('command', 255)->primary();
                // module | core
                $table->string('command_type', 16)->default('');
                // <module> | core
                $table->string('source', 64)->default('');
                // Source string / translation key (never translated at sync time)
                $table->string('description', 255)->nullable()->default(null);
                // JSON list of structured parameter descriptors (if known)
                $table->text('params')->nullable()->default(null);
                $table->timestamp('updated_at', 0)->useCurrent();
            });
        }
    }
}
