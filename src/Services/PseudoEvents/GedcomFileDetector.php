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

namespace Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Webtrees;

use function array_keys;
use function array_merge;
use function array_unique;
use function is_file;
use function stat;

/**
 * Detects that a GEDCOM file on disk changed (an import or an export
 * re-writes data/<gedcom_filename>). This is the only way to observe a
 * standard import/edit without a core change.
 *
 * State shape: map of gedcom_id => ['mtime' => int, 'size' => int, 'name' => string]
 * for every tree whose GEDCOM file exists. A transition (new/removed tree, or
 * a changed mtime/size) fires `cronjob:gedcom-changed`.
 */
class GedcomFileDetector implements PseudoEventDetectorInterface {

    public function eventName(): string {
        return 'cronjob:gedcom-changed';
    }

    public function label(): string {
        return 'GEDCOM file changed';
    }

    /**
     * @return array<int, array{mtime: int, size: int, name: string}>
     */
    public function currentState(): mixed {
        $rows  = DB::table('gedcom')->get(['gedcom_id', 'gedcom_filename', 'gedcom_name']);
        $state = [];

        foreach ($rows as $row) {
            $file = Webtrees::DATA_DIR . (string) $row->gedcom_filename;
            if (!is_file($file)) {
                continue;
            }
            $st = @stat($file);
            if ($st === false) {
                continue;
            }
            $state[(int) $row->gedcom_id] = [
                'mtime' => (int) $st['mtime'],
                'size'  => (int) $st['size'],
                'name'  => (string) $row->gedcom_name,
            ];
        }

        return $state;
    }

    /**
     * @param mixed $last_state
     * @param mixed $current_state
     *
     * @return array{detected: bool, state: mixed, payload: array<string, mixed>}
     */
    public function compare(mixed $last_state, mixed $current_state): array {
        if ($last_state === null) {
            return ['detected' => false, 'state' => $current_state, 'payload' => []];
        }

        $last    = (array) $last_state;
        $current = (array) $current_state;

        $changed = [];
        $ids     = array_unique(array_merge(array_keys($last), array_keys($current)));
        foreach ($ids as $id) {
            $a       = $last[$id] ?? null;
            $b       = $current[$id] ?? null;
            $a_mtime = ($a === null || !isset($a['mtime'])) ? null : (int) $a['mtime'];
            $b_mtime = ($b === null || !isset($b['mtime'])) ? null : (int) $b['mtime'];
            $a_size  = ($a === null || !isset($a['size'])) ? null : (int) $a['size'];
            $b_size  = ($b === null || !isset($b['size'])) ? null : (int) $b['size'];

            if ($a_mtime !== $b_mtime || $a_size !== $b_size) {
                $changed[] = (string) (($b['name'] ?? null) ?? ($a['name'] ?? null) ?? (string) $id);
            }
        }

        return [
            'detected' => $changed !== [],
            'state'    => $current_state,
            'payload'  => ['changed' => $changed],
        ];
    }
}
