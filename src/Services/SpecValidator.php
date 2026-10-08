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

use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function preg_match;
use function strlen;
use function substr;
use function trim;

/**
 * Normalization and validation of job / event / command specs offered by
 * external modules (a <module>/cron-jobs.php manifest or a marker method).
 *
 * The specs are plain arrays - deliberately NOT a shared class, so offering
 * modules never reference the cronjob namespace.
 */
final class SpecValidator {

    // Job-spec timeout bounds; single source of truth (CronjobModule's form
    // limits reference these). Kept here so validateJobSpec() stays free of a
    // CronjobModule dependency (standalone-testable without the webtrees core).
    public const TIMEOUT_MIN = 30;
    public const TIMEOUT_MAX = 3600;
    public const TIMEOUT_STD = 300;

    /**
     * Normalize + validate a job spec offered by an external module (a
     * <module>/cron-jobs.php manifest or a getCronJobs() marker method).
     *
     * The spec is a plain array - deliberately NOT a shared class, so offering
     * modules never reference the cronjob namespace. Returns the normalized
     * spec plus any validation errors; when errors is empty the spec is ready
     * to be upserted. W1: `command` is an ordinary module/core command (the
     * W1 wrapper derives its payload from the entry script, so there is no
     * `--logic` path to confine here).
     *
     * @param array<string, mixed>  $spec
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    public static function validateJobSpec(array $spec, ?string $root_dir = null): array {
        $root_dir ??= Webtrees::ROOT_DIR;
        $errors     = [];

        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '' || strlen($name) > 64 || preg_match('/^[a-z0-9_\-]+$/', $name) !== 1) {
            $errors[] = 'name must be a non-empty slug of [a-z0-9_-], max 64 chars';
        }

        $title = trim((string) ($spec['title'] ?? ''));
        if ($title === '') {
            $title = $name;
        }
        if (strlen($title) > 128) {
            $errors[] = 'title must be at most 128 chars';
        }

        $trigger_result = TriggerService::normalizeTriggers($spec);
        foreach ($trigger_result['errors'] as $error) {
            $errors[] = $error;
        }
        $triggers = $trigger_result['triggers'];

        $command_type = (string) ($spec['command_type'] ?? '');
        if (!in_array($command_type, ['module', 'core'], true)) {
            $errors[] = "command_type must be 'module' or 'core'";
        }

        $command = trim((string) ($spec['command'] ?? ''));
        $args    = trim((string) ($spec['args'] ?? ''));
        if (in_array($command_type, ['module', 'core'], true)) {
            $built = JobRunner::buildArgv(
                ['command_type' => $command_type, 'command' => $command, 'args' => $args],
                $root_dir
            );
            if ($built['error'] !== '') {
                $errors[] = $built['error'];
            }
        }

        $timeout = (int) ($spec['timeout_sec'] ?? self::TIMEOUT_STD);
        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            $errors[] = 'timeout_sec must be between ' . self::TIMEOUT_MIN . ' and ' . self::TIMEOUT_MAX;
            $timeout = self::TIMEOUT_STD;
        }

        $enabled = (bool) ($spec['enabled'] ?? false);

        return [
            'spec'   => [
                'name'         => $name,
                'title'        => $title,
                'triggers'     => $triggers,
                'command_type' => $command_type,
                'command'      => $command,
                'args'         => $args,
                'enabled'      => $enabled,
                'timeout_sec'  => $timeout,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Normalize + validate an event spec announced by an external module.
     *
     * The spec is a plain array - deliberately NOT a shared class, so
     * announcing modules never reference the cronjob namespace.
     *
     * @param array<string, mixed> $spec
     * @return array{spec: array{name: string, description: string, payload: list<string>}, errors: list<string>}
     */
    public static function validateEventSpec(array $spec): array {
        $errors = [];

        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '' || strlen($name) > 64 || preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $name) !== 1) {
            $errors[] = 'event name must be a non-empty slug of [a-z0-9_-] (max 64 chars)';
        }

        $description = trim((string) ($spec['description'] ?? ''));
        if (strlen($description) > 255) {
            $errors[] = 'description must be at most 255 chars';
        }

        $payload = $spec['payload'] ?? [];
        if (!is_array($payload)) {
            $errors[] = 'payload must be a list of parameter names';
            $payload  = [];
        }
        $payload = array_values(array_filter(
            array_map('strval', array_values($payload)),
            static fn (string $p): bool => $p !== ''
        ));
        if (count($payload) > 8) {
            $errors[] = 'payload must list at most 8 parameter names';
        }

        return [
            'spec'   => [
                'name'        => $name,
                'description' => $description,
                'payload'     => $payload,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Normalize + validate a command spec announced by an external module
     * (a <module>/cron-jobs.php 'commands' entry or a getModuleCommands()
     * marker method). Mirrors validateEventSpec(): a plain array, deliberately
     * NOT a shared class, so announcing modules never reference the cronjob
     * namespace.
     *
     * Spec shape: ['command' => string, 'command_type' => ?'module'|'core',
     * 'description' => ?string, 'params' => ?list<array{name: string,
     * optional: ?bool, default: ?string, description: ?string}>]. When
     * command_type is omitted it is derived from the command value
     * (CronjobUtils::detectCommandType). `params` is the structured
     * "available parameters" list shown in the job form.
     *
     * @param array<string, mixed> $spec
     *
     * @return array{spec: array{command: string, command_type: string, description: string, params: list<array<string, mixed>>}, errors: list<string>}
     */
    public static function validateCommandSpec(array $spec): array {
        $errors = [];

        $command = trim((string) ($spec['command'] ?? ''));
        if ($command === '' || strlen($command) > 255) {
            $errors[] = 'command must be a non-empty path/name of at most 255 chars';
        }

        $command_type = trim((string) ($spec['command_type'] ?? ''));
        if ($command_type === '') {
            $command_type = CronjobUtils::detectCommandType($command);
        }
        if (!in_array($command_type, ['module', 'core'], true)) {
            $errors[] = "command_type must be 'module' or 'core' (derivable from the command value)";
        }

        $description = trim((string) ($spec['description'] ?? ''));
        if (strlen($description) > 255) {
            $errors[] = 'description must be at most 255 chars';
        }

        $raw_params = $spec['params'] ?? [];
        if (!is_array($raw_params)) {
            $errors[] = 'params must be a list of parameter descriptors';
            $raw_params = [];
        }
        $params = [];
        foreach (array_values($raw_params) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === '' || strlen($name) > 64 || preg_match('/\s/', $name) === 1) {
                continue; // malformed descriptor - drop it silently
            }
            $default = (isset($param['default']) && $param['default'] !== null) ? (string) $param['default'] : null;
            if ($default !== null && strlen($default) > 128) {
                $default = substr($default, 0, 128);
            }
            $params[] = [
                'name'        => $name,
                'optional'    => array_key_exists('optional', $param) ? (bool) $param['optional'] : true,
                'default'     => $default,
                'description' => trim((string) ($param['description'] ?? '')),
            ];
        }
        if (count($params) > 8) {
            $errors[] = 'params must list at most 8 parameter descriptors';
        }

        return [
            'spec'   => [
                'command'      => $command,
                'command_type' => $command_type,
                'description'  => $description,
                'params'       => $params,
            ],
            'errors' => $errors,
        ];
    }
}
