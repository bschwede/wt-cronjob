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

namespace Schwendinger\Webtrees\Module\Cronjob\Services;

use Fisharebest\Webtrees\DB;
use PDOException;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Schwendinger\Webtrees\Helpers\MoreI18N;

use function array_key_exists;
use function array_values;
use function explode;
use function is_array;
use function json_decode;
use function json_encode;
use function str_replace;
use function strval;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The command inventory (§12): every command a job may run, with its
 * available parameters.
 *
 * Sources (merged by mergeCatalog(), first source wins per command):
 *  1. the allowlisted core commands (JobRunner::ALLOWED_CORE_COMMANDS), with
 *     their known parameters
 *  2. commands announced by modules (manifest 'commands' section or a
 *     getModuleCommands() marker method)
 *  3. module CLI scripts found by glob (CronjobUtils::jobScriptCandidates()),
 *     EXCEPT for a module that announced commands - such a module curates its
 *     own commands, so its globbed scripts (e.g. cronjob's tick/watch/wrap)
 *     are suppressed and must not be offered as jobs.
 *
 * The result is synced to the cj_command_catalog table by the tick (like the
 * event inventory), so the admin UI and the job form read a small table
 * instead of re-collecting. Descriptions are stored as source strings
 * (translation keys) and translated only at render time.
 */
final class CommandCatalogService {

    /** Known parameters of the allowlisted core commands (informational). */
    private static ?array $CORE_PARAMS = null;

    /** Short descriptions for the allowlisted core commands. */
    private static ?array $CORE_DESCRIPTIONS = null;

    private static function initCoreSettings() {
        if (!self::$CORE_PARAMS) {
            self::$CORE_PARAMS = [
                'site-setting' => [
                    [
                        'name' => '--list', 
                        'optional' => true,
                        'description' => MoreI18N::translate('Read-only: list all site settings.')
                    ],
                ],
            ];
        }

        if (!self::$CORE_DESCRIPTIONS) {
            self::$CORE_DESCRIPTIONS = [
                'tree-export' => MoreI18N::translate('Export a whole tree as a GEDCOM file into data directory (full personal data).'),
                'tree-list' => MoreI18N::translate('List all trees.'),
                'user-list' => MoreI18N::translate('List all users (includes e-mail addresses).'),
                'site-setting' => MoreI18N::translate('Read or write site settings (a job may only use the read-only --list argument).'),
            ];            
        }
    }

    /**
     * Collect all known commands (live, no DB sync).
     *
     * @return array<string, array{command_type: string, source: string, description: string, params: list<array<string, mixed>>}>
     *         keyed by command value
     */
    public static function collect(): array {
        self::initCoreSettings();
        $core = [];
        foreach (JobRunner::ALLOWED_CORE_COMMANDS as $command) {
            $core[] = [
                'command'      => $command,
                'command_type' => 'core',
                'source'       => 'core',
                'description'  => self::$CORE_DESCRIPTIONS[$command] ?? '',
                'params'       => self::$CORE_PARAMS[$command] ?? [],
            ];
        }

        $globbed = [];
        foreach (CronjobUtils::jobScriptCandidates() as $path) {
            $globbed[] = ['command' => $path, 'module' => self::moduleOf($path)];
        }

        return self::mergeCatalog($core, ScheduleService::discoverExternalCommands(), $globbed);
    }

    /**
     * Merge the three sources into the catalog (pure, standalone-testable).
     * First source wins per command; a module that announced commands has its
     * globbed scripts suppressed (see class docblock).
     *
     * @param list<array{command: string, command_type: string, source: string, description: string, params: list<array<string, mixed>>}> $core
     * @param list<array{module: string, command: string, command_type: string, description: string, params: list<array<string, mixed>>}> $announced
     * @param list<array{command: string, module: string}> $globbed
     *
     * @return array<string, array{command_type: string, source: string, description: string, params: list<array<string, mixed>>}>
     */
    public static function mergeCatalog(array $core, array $announced, array $globbed): array {
        $catalog = [];

        foreach ($core as $entry) {
            $command = strval($entry['command'] ?? '');
            if ($command === '' || isset($catalog[$command])) {
                continue;
            }
            $catalog[$command] = [
                'command_type' => strval($entry['command_type'] ?? ''),
                'source'       => strval($entry['source'] ?? ''),
                'description'  => strval($entry['description'] ?? ''),
                'params'       => self::normalizeParams($entry['params'] ?? []),
            ];
        }

        $announced_modules = [];
        foreach ($announced as $entry) {
            $command = strval($entry['command'] ?? '');
            if ($command !== '') {
                $announced_modules[strval($entry['module'] ?? '')] = true;
            }
            if ($command === '' || isset($catalog[$command])) {
                continue;
            }
            $catalog[$command] = [
                'command_type' => strval($entry['command_type'] ?? ''),
                'source'       => strval($entry['module'] ?? ''),
                'description'  => strval($entry['description'] ?? ''),
                'params'       => self::normalizeParams($entry['params'] ?? []),
            ];
        }

        foreach ($globbed as $entry) {
            $command = strval($entry['command'] ?? '');
            $module  = strval($entry['module'] ?? '');
            if ($command === '' || isset($catalog[$command]) || isset($announced_modules[$module])) {
                continue;
            }
            $catalog[$command] = [
                'command_type' => 'module',
                'source'       => $module,
                'description'  => '',
                'params'       => [],
            ];
        }

        return $catalog;
    }

    /**
     * Sync the collected catalog into cj_command_catalog (upsert + delete
     * vanished). Called by the tick - cheap, offline-safe, exception-safe at
     * the call site (a catalog problem must never break the tick).
     */
    public static function syncCatalog(string $now): void {
        $rows = self::collect();

        $seen = [];
        foreach ($rows as $command => $entry) {
            $seen[] = $command;
            DB::table('cj_command_catalog')->updateOrInsert(['command' => $command], [
                'command_type' => $entry['command_type'],
                'source'       => $entry['source'],
                'description'  => $entry['description'] !== '' ? $entry['description'] : null,
                'params'       => $entry['params'] !== []
                    ? json_encode($entry['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'updated_at'   => $now,
            ]);
        }

        if ($seen !== []) {
            DB::table('cj_command_catalog')->whereNotIn('command', $seen)->delete();
        }
    }

    /**
     * The catalog for display: the synced table, falling back to the live
     * collect() while the table is empty (before the first tick sync).
     *
     * @return array<string, array{command_type: string, source: string, description: string, params: list<array<string, mixed>>}>
     */
    public static function listCatalog(): array {
        try {
            $rows = DB::table('cj_command_catalog')->orderBy('command')->get()->all();
        } catch (PDOException) {
            return self::collect();
        }

        if ($rows === []) {
            return self::collect();
        }

        $out = [];
        foreach ($rows as $row) {
            $params = [];
            $raw    = strval($row->params ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $params = self::normalizeParams(array_values($decoded));
                }
            }
            $out[strval($row->command)] = [
                'command_type' => strval($row->command_type ?? ''),
                'source'       => strval($row->source ?? ''),
                'description'  => strval($row->description ?? ''),
                'params'       => $params,
            ];
        }

        return $out;
    }

    /**
     * The short module name of a relative script path
     * (modules_v4/<module>/cli/<script>.php -> <module>).
     */
    private static function moduleOf(string $path): string {
        $parts = explode('/', str_replace('\\', '/', $path));

        return $parts[1] ?? '';
    }

    /**
     * Canonicalize a structured parameter list (defensive: drops entries
     * without a name).
     *
     * @param list<array<string, mixed>> $params
     *
     * @return list<array{name: string, optional: bool, default: string|null, description: string}>
     */
    private static function normalizeParams(array $params): array {
        $out = [];
        foreach (array_values($params) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $default = (isset($param['default']) && $param['default'] !== null) ? (string) $param['default'] : null;
            $out[]   = [
                'name'        => $name,
                'optional'    => array_key_exists('optional', $param) ? (bool) $param['optional'] : true,
                'default'     => $default,
                'description' => trim((string) ($param['description'] ?? '')),
            ];
        }

        return $out;
    }
}
