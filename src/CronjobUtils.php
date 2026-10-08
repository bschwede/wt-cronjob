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

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;
use Throwable;

use function array_is_list;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function str_replace;

/**
 * Module-level helpers: script discovery, manifest loading, command-type
 * detection and i18n. (Naming lives in Services\JobNaming, trigger
 * installation blocks in Services\TriggerInstallService, job registry
 * access in Services\JobRepository.)
 */
final class CronjobUtils {

    public const MODULE_NAME = '_cronjob_';

    public const SCHEMA_NAME = 'SCHEMA_VERSION';

    private const MODULE_SCRIPT_PATTERN = '#^modules_v4/[a-z0-9_\-]+/cli/[a-z0-9_\-]+\.php$#';

    /**
     * Discover all module CLI scripts that may be used as jobs
     * (modules_v4/&lt;module&gt;/cli/&lt;script&gt;.php, template files with a '_' prefix excluded).
     *
     * @return list<string> relative paths, e.g. 'modules_v4/linkenhancer/cli/build-link-index.php'
     */
    public static function jobScriptCandidates(): array {
        $root = Webtrees::MODULES_DIR;

        $files = glob($root . '*/cli/*.php') ?: [];

        $candidates = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (str_starts_with($base, '_')) {
                continue;
            }
            $candidates[] = str_replace(
                [Webtrees::ROOT_DIR, '\\'],
                ['', '/'],
                $file
            );
        }
        sort($candidates);

        return $candidates;
    }

    /**
     * Load a <module>/cron-jobs.php manifest in an isolated scope.
     *
     * The file returns either a plain list of job specs (simple form) or an
     * associative array with optional 'jobs', 'events' and 'commands' lists
      * (event announcements, see JobDiscovery::discoverExternalEvents(),
      * and command announcements, see
      * JobDiscovery::discoverExternalCommands()). Returns the normalized
     * ['jobs' => …, 'events' => …, 'commands' => …] shape, or three empty
     * lists on any error - a broken or misbehaving manifest must never break
     * the tick. Same containment strategy as core's ModuleService::load()
     * (include in a try/catch); a manifest that calls exit() cannot be
     * contained (same limitation as module.php).
     *
     * @return array{jobs: list<array<string, mixed>>, events: list<array<string, mixed>>, commands: list<array<string, mixed>>}
     */
    public static function loadManifestFile(string $path): array {
        if (!is_file($path)) {
            return ['jobs' => [], 'events' => [], 'commands' => []];
        }

        $loader = static function (string $p): array {
            $result = include $p;
            if (!is_array($result)) {
                return ['jobs' => [], 'events' => [], 'commands' => []];
            }
            if (array_is_list($result)) {
                $jobs = [];
                foreach ($result as $spec) {
                    if (is_array($spec)) {
                        $jobs[] = $spec;
                    }
                }

                return ['jobs' => $jobs, 'events' => [], 'commands' => []];
            }

            $jobs     = $result['jobs'] ?? [];
            $events   = $result['events'] ?? [];
            $commands = $result['commands'] ?? [];

            return [
                'jobs'     => is_array($jobs) ? array_values($jobs) : [],
                'events'   => is_array($events) ? array_values($events) : [],
                'commands' => is_array($commands) ? array_values($commands) : [],
            ];
        };

        try {
            return $loader($path);
        } catch (Throwable) {
            return ['jobs' => [], 'events' => [], 'commands' => []];
        }
    }

    /**
     * Determine the command type from the command value
     * (the form uses a single field; the runner validates both).
     *
     * @return 'module'|'core'|''
     */
    public static function detectCommandType(string $command): string {
        if (preg_match(self::MODULE_SCRIPT_PATTERN, $command) === 1) {
            return 'module';
        }
        if (in_array($command, JobRunner::ALLOWED_CORE_COMMANDS, true)) {
            return 'core';
        }
        return '';
    }

    /**
     * Render-time translation of a (admin-renamable) job title.
     *
     * I18N::translate() applies sprintf() to the lookup result; a title
     * containing a bare '%' (user data) would be an invalid conversion
     * specification (PHP warning + false → TypeError in I18N::translate():
     * string). Admin-renamed titles are user data anyway, so we skip
     * translation for those instead of risking a view crash.
     */
    public static function translateJobTitle(string $title): string {
        if (str_contains($title, '%')) {
            return $title;
        }

        return I18N::translate($title);
    }
}
