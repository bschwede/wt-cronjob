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

use Fisharebest\Webtrees\Module\ModuleInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Throwable;

use function array_flip;
use function array_intersect_key;
use function array_merge;
use function array_values;
use function in_array;
use function is_array;
use function is_file;
use function method_exists;
use function str_contains;
use function strlen;
use function trim;

/**
 * External job self-registration (phase 2, §6.3): discovering jobs,
 * events and commands offered by other enabled modules and syncing them
 * into cj_job.
 *
 * Discovery is exception-safe - a broken or throwing source is skipped
 * and must never break the tick.
 */
final class JobDiscovery {

    /**
     * Discover jobs offered by other enabled modules: via a
     * <module>/cron-jobs.php manifest file and/or a getCronJobs() marker
     * method. Returns one entry per valid spec:
     * `['module' => <short>, 'spec' => normalized]`. Malformed or throwing
     * sources are skipped - they must never break the tick.
     *
     * @return list<array{module: string, spec: array<string, mixed>}>
     */
    public static function discoverExternalJobs(?string $root_dir = null): array {
        $root_dir ??= Webtrees::ROOT_DIR;
        $found    = [];

        try {
            $modules = Registry::container()->get(ModuleService::class)->all(false);
        } catch (Throwable) {
            return $found;
        }

        /** @var ModuleInterface $module */
        foreach ($modules as $module) {
            // MODULES_DIR is absolute with a trailing slash; a custom module's
            // directory is exactly its name minus the surrounding underscores.
            // Core modules have no modules_v4/<name>/ dir, so is_file() below
            // filters them out naturally - no separate custom-module guard.
            $short = trim($module->name(), '_');
            $dir   = Webtrees::MODULES_DIR . $short . DIRECTORY_SEPARATOR;

            $raw_specs = [];
            $manifest  = $dir . 'cron-jobs.php';
            if (is_file($manifest)) {
                $raw_specs = array_merge($raw_specs, CronjobUtils::loadManifestFile($manifest)['jobs']);
            }
            if (method_exists($module, 'getCronJobs')) {
                try {
                    $offered = $module->getCronJobs();
                } catch (Throwable) {
                    $offered = [];
                }
                if (is_array($offered)) {
                    $raw_specs = array_merge($raw_specs, array_values($offered));
                }
            }

            foreach ($raw_specs as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $result = SpecValidator::validateJobSpec($raw, $root_dir);
                if ($result['errors'] !== []) {
                    continue;
                }
                $spec = $result['spec'];
                // Announced module events are namespaced <module>:<event>, like
                // the offered job keys. A name that already carries a colon
                // (e.g. a listener for cronjob:log-edit-update) is kept as-is.
                foreach ($spec['triggers'] as $i => $trigger) {
                    if ($trigger['type'] === TriggerService::TRIGGER_EVENT && !str_contains($trigger['event'], ':')) {
                        $spec['triggers'][$i]['event'] = $short . ':' . $trigger['event'];
                    }
                }
                $found[] = ['module' => $short, 'spec' => $spec];
            }
        }

        return $found;
    }

    /**
     * Discover events announced by other enabled modules: via the 'events'
     * section of a <module>/cron-jobs.php manifest and/or a getModuleEvents()
     * marker method. Exception-safe like discoverExternalJobs() - a broken or
     * throwing source is skipped. Announced names are plain slugs; the catalog
     * namespaces them <module>:<name> (like the offered job keys).
     *
     * Event spec shape: ['name' => slug, 'description' => ?string,
     * 'payload' => ?list<string>].
     *
     * @return list<array{module: string, name: string, description: string, payload: list<string>}>
     */
    public static function discoverExternalEvents(): array {
        $found = [];

        try {
            $modules = Registry::container()->get(ModuleService::class)->all(false);
        } catch (Throwable) {
            return $found;
        }

        /** @var ModuleInterface $module */
        foreach ($modules as $module) {
            $short = trim($module->name(), '_');
            $dir   = Webtrees::MODULES_DIR . $short . DIRECTORY_SEPARATOR;

            $raw_events = [];
            $manifest   = $dir . 'cron-jobs.php';
            if (is_file($manifest)) {
                $raw_events = array_merge($raw_events, CronjobUtils::loadManifestFile($manifest)['events']);
            }
            if (method_exists($module, 'getModuleEvents')) {
                try {
                    $announced = $module->getModuleEvents();
                } catch (Throwable) {
                    $announced = [];
                }
                if (is_array($announced)) {
                    $raw_events = array_merge($raw_events, array_values($announced));
                }
            }

            foreach ($raw_events as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $result = SpecValidator::validateEventSpec($raw);
                if ($result['errors'] !== []) {
                    continue;
                }
                $found[] = [
                    'module'      => $short,
                    'name'        => (string) $result['spec']['name'],
                    'description' => (string) $result['spec']['description'],
                    'payload'     => $result['spec']['payload'],
                ];
            }
        }

        return $found;
    }

    /**
     * Discover commands announced by other enabled modules: via the 'commands'
     * section of a <module>/cron-jobs.php manifest and/or a getModuleCommands()
     * marker method. Exception-safe like discoverExternalEvents() - a broken
     * or throwing source is skipped. Command values are used as-is (module
     * script paths or core command names) - no namespacing.
     *
     * @return list<array{module: string, command: string, command_type: string, description: string, params: list<array<string, mixed>>}>
     */
    public static function discoverExternalCommands(): array {
        $found = [];

        try {
            $modules = Registry::container()->get(ModuleService::class)->all(false);
        } catch (Throwable) {
            return $found;
        }

        /** @var ModuleInterface $module */
        foreach ($modules as $module) {
            $short = trim($module->name(), '_');
            $dir   = Webtrees::MODULES_DIR . $short . DIRECTORY_SEPARATOR;

            $raw_commands = [];
            $manifest     = $dir . 'cron-jobs.php';
            if (is_file($manifest)) {
                $raw_commands = array_merge($raw_commands, CronjobUtils::loadManifestFile($manifest)['commands']);
            }
            if (method_exists($module, 'getModuleCommands')) {
                try {
                    $announced = $module->getModuleCommands();
                } catch (Throwable) {
                    $announced = [];
                }
                if (is_array($announced)) {
                    $raw_commands = array_merge($raw_commands, array_values($announced));
                }
            }

            foreach ($raw_commands as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $result = SpecValidator::validateCommandSpec($raw);
                if ($result['errors'] !== []) {
                    continue;
                }
                $found[] = [
                    'module'       => $short,
                    'command'      => (string) $result['spec']['command'],
                    'command_type' => (string) $result['spec']['command_type'],
                    'description'  => (string) $result['spec']['description'],
                    'params'       => $result['spec']['params'],
                ];
            }
        }

        return $found;
    }

    /**
     * The spec currently offered for an already-adopted job (name =
     * <module>:<name>), or null if no enabled module offers it any more.
     * Backs the admin "reset to module defaults" action.
     *
     * @param list<array{module: string, spec: array<string, mixed>}>|null $offered
     *                                                                       discovery result (injected for tests)
     * @return array<string, mixed>|null normalized spec
     */
    public static function findOfferedSpec(string $job_name, ?array $offered = null): ?array {
        $offered ??= self::discoverExternalJobs();

        foreach ($offered as $entry) {
            $key = trim((string) $entry['module'], '_') . ':' . (string) ($entry['spec']['name'] ?? '');
            if ($key === $job_name) {
                return $entry['spec'];
            }
        }

        return null;
    }

    /**
     * Ensure a discovered job exists in cj_job, keyed as <module>:<name>.
     *
     * Insert-if-missing: once created the row is admin-owned and is never
     * overwritten by the manifest, so admin edits to cron/args/enabled are
     * safe across ticks. A key longer than cj_job.name (VARCHAR 64) is
     * skipped rather than silently truncated (collision risk).
     */
    public static function upsertDiscoveredJob(string $module, array $spec, string $now): void {
        $key = trim($module, '_') . ':' . (string) $spec['name'];
        if (strlen($key) === 0 || strlen($key) > 64 || JobRepository::findByName($key) !== null) {
            return;
        }

        $triggers = (array) ($spec['triggers'] ?? []);

        $job_id = JobRepository::insertJob([
            'name'         => $key,
            'title'        => (string) $spec['title'],
            'command_type' => (string) $spec['command_type'],
            'command'      => (string) $spec['command'],
            'args'         => (string) $spec['args'],
            'enabled'      => ($spec['enabled'] ? 1 : 0),
            'timeout_sec'  => (int) $spec['timeout_sec'],
            'next_run_at'  => TriggerService::nextRunMin($triggers, $now),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        JobRepository::replaceJobTriggers($job_id, $triggers);
    }

    /**
     * Sync externally-offered jobs into cj_job. Called by the tick; cheap (a
     * filesystem glob + a few inserts/updates at most).
     *
     * Jobs offered by OTHER modules are insert-if-missing (admin-owned once
     * created). cronjob's OWN job is force-synced to its manifest every tick,
     * so a module update (manifest change) converges within one tick.
     */
    public static function syncDiscoveredJobs(): void {
        $now = JobRepository::now();
        foreach (self::discoverExternalJobs() as $entry) {
            if (trim((string) $entry['module'], '_') === trim(CronjobUtils::MODULE_NAME, '_')) {
                self::syncOwnOfferedJob($entry['spec'], $now);
            } else {
                self::upsertDiscoveredJob($entry['module'], $entry['spec'], $now);
            }
        }
    }

    /**
     * The cj_job columns a manifest controls, normalized to DB-ready values.
     * The admin keeps ownership of name/enabled/notify/created_at and the run
     * history - those are never force-synced.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function mirrorValues(array $spec): array {
        return [
            'title'        => (string) ($spec['title'] ?? ''),
            // Canonical trigger key (§13) - NOT a cj_job column, consumed by
            // syncOwnOfferedJob() as the change marker for replaceJobTriggers().
            'triggers_key' => TriggerService::triggersKey((array) ($spec['triggers'] ?? [])),
            'command_type' => (string) ($spec['command_type'] ?? ''),
            'command'      => (string) ($spec['command'] ?? ''),
            'args'         => (string) ($spec['args'] ?? ''),
            'timeout_sec'  => (int) ($spec['timeout_sec'] ?? SpecValidator::TIMEOUT_STD),
        ];
    }

    /**
     * The mirrored fields (see mirrorValues()) whose stored value differs from
     * the offered spec. Pure and side-effect free (standalone-testable).
     *
     * @param array<string, mixed> $row  current cj_job values (the mirrored fields)
     * @param array<string, mixed> $spec normalized offered spec
     *
     * @return list<string> field names that differ
     */
    public static function specDiff(array $row, array $spec): array {
        $want = self::mirrorValues($spec);
        $diff = [];
        foreach ($want as $field => $value) {
            $have = $row[$field] ?? null;
            $have = ($field === 'timeout_sec') ? (int) $have : (string) $have;
            $ref  = ($field === 'timeout_sec') ? (int) $value : (string) $value;
            if ($have !== $ref) {
                $diff[] = $field;
            }
        }

        return $diff;
    }

    /**
     * Re-sync cronjob's OWN offered job (keyed <selfmodule>:<name>) to the
     * current manifest. Insert-if-missing; when the row exists, update only
     * the manifest-mirrored fields that changed - the admin's name/enabled/
     * notify/created_at and the run history are preserved, and next_run_at is
     * only rescheduled when the trigger set itself changed (§13).
     *
     * @param array<string, mixed> $spec normalized offered spec
     */
    public static function syncOwnOfferedJob(array $spec, string $now): void {
        $key = trim(CronjobUtils::MODULE_NAME, '_') . ':' . (string) ($spec['name'] ?? '');
        if ($key === '' || strlen($key) > 64) {
            return;
        }

        $job = JobRepository::findByName($key);
        if ($job === null) {
            self::upsertDiscoveredJob(CronjobUtils::MODULE_NAME, $spec, $now);

            return;
        }

        $row  = [
            'title'        => (string) $job->title,
            'triggers_key' => TriggerService::triggersKey(JobRepository::jobTriggers((int) $job->id)),
            'command_type' => (string) $job->command_type,
            'command'      => (string) $job->command,
            'args'         => (string) $job->args,
            'timeout_sec'  => (int) $job->timeout_sec,
        ];
        $diff = self::specDiff($row, $spec);
        if ($diff === []) {
            return;
        }

        $triggers = (array) ($spec['triggers'] ?? []);
        $values   = array_intersect_key(self::mirrorValues($spec), array_flip($diff));
        unset($values['triggers_key']); // not a cj_job column
        $values['updated_at'] = $now;

        // Re-place the triggers and reschedule only when the schedule itself changed.
        if (in_array('triggers_key', $diff, true)) {
            JobRepository::replaceJobTriggers((int) $job->id, $triggers);
            $values['next_run_at'] = TriggerService::nextRunMin($triggers, $now);
        }

        JobRepository::updateJobById((int) $job->id, $values);
    }
}
