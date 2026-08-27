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
 * Upgrade the database schema from version 4 to version 5 (§12).
 *
 * Creates the command inventory:
 *  - `cj_command_catalog` — every command a job may run (core allowlist,
 *    module announcements, globbed module scripts) with its available
 *    parameters. Synced by the tick (CommandCatalogService).
 */
class Migration4 implements MigrationInterface
{
    public function upgrade(): void
    {
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
