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
 * Upgrade the database schema from version 3 to version 4 (§11).
 *
 * Creates the event inventory:
 *  - `cj_event_catalog` — all known event names: module announcements,
 *    built-in pseudo-events, route events and names currently listened
 *    for by an enabled job. Synced by the tick (EventCatalogService).
 */
class Migration3 implements MigrationInterface
{
    public function upgrade(): void
    {
        // =========================================================================
        // Table 5: cj_event_catalog (event inventory)
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
    }
}
