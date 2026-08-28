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

namespace Schwendinger\Webtrees\Module\Cronjob;

/**
 * Extraction marker for xgettext (keyword "translate").
 *
 * The cron-jobs.php manifest is pure data, loaded in tick/CLI context where
 * no UI language is active - I18N::translate() must not run there (it also
 * applies sprintf() to its result). Wrapping a literal in
 * I18nMark::translate() marks it for extraction (the last qualified name
 * component "translate" matches the xgettext keyword) while returning the
 * string unchanged. Actual translation happens at render time in the views
 * (I18N::translate() / CronjobUtils::translateJobTitle()).
 */
final class I18nMark {

    /**
     * Identity function: returns the argument unchanged.
     */
    public static function translate(string $text): string {
        return $text;
    }
}
