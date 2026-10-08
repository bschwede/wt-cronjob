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

use DomainException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use RuntimeException;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function mb_strlen;
use function max;
use function min;
use function strlen;
use function trim;

/**
 * Job form logic: building the form state for the GET action, validating
 * submitted form data, and computing cron previews. Pure - standalone-
 * testable without the webtrees core (validate() and cronPreviews()) or
 * a database.
 */
final class JobFormHandler {

    /**
     * Build the form state for the job create/edit form (GET action).
     *
     * Handles the duplicate flow (clone + strip module prefix + generate
     * copy slug), the trigger list building from the DB, and the PRG
     * stash restoration (one-shot pull from the session).
     *
     * @param object|null $job       the job row (null for a new job)
     * @param int         $duplicate_id the source job id when duplicating
     * @param array<string, mixed>|null $stashed  PRG stash (from Session::pull)
     *
     * @return array{job: object|null, copy_of: string|null, triggers_time: list<string>, triggers_event: list<string>, notify: bool, form_values: array<string, mixed>|null}
     */
    public static function formState(?object $job, int $duplicate_id, ?array $stashed): array {
        $copy_of = null;

        if ($job === null && $duplicate_id > 0) {
            $source = JobRepository::findById($duplicate_id);
            if ($source !== null) {
                $job          = clone $source;
                $job->id      = 0;
                $job->enabled = 0;
                $job->name    = JobNaming::uniqueCopySlug(JobNaming::copySlugBase((string) $source->name));
                $copy_of      = (string) $source->title;
            }
        }

        $triggers_time  = [];
        $triggers_event = [];
        $trigger_source_id = 0;
        if ($job !== null) {
            $trigger_source_id = (int) $job->id;
        } elseif ($duplicate_id > 0) {
            $trigger_source_id = $duplicate_id;
        }
        if ($trigger_source_id > 0) {
            foreach (JobRepository::jobTriggers($trigger_source_id) as $trigger) {
                if ($trigger['type'] === TriggerService::TRIGGER_TIME) {
                    $triggers_time[] = $trigger['cron'];
                } else {
                    $triggers_event[] = $trigger['event'];
                }
            }
        }
        if ($triggers_time === []) {
            $triggers_time = [''];
        }

        $notify      = $job !== null ? ((int) $job->notify === 1) : false;
        $form_values = null;

        if (is_array($stashed) && is_array($stashed['values'] ?? null)) {
            $form_values    = $stashed['values'];
            $triggers_time  = array_values(array_map('strval', (array) ($stashed['values']['triggers_time'] ?? $triggers_time)));
            $triggers_event = array_values(array_map('strval', (array) ($stashed['values']['triggers_event'] ?? $triggers_event)));
            $notify         = (bool) ($stashed['notify'] ?? $notify);
            if ($copy_of === null) {
                $copy_of = $stashed['copy_of'] ?? null;
            }
        }

        return [
            'job'            => $job,
            'copy_of'        => $copy_of,
            'triggers_time'  => $triggers_time,
            'triggers_event' => $triggers_event,
            'notify'         => $notify,
            'form_values'    => $form_values,
        ];
    }

    /**
     * Validate submitted job form data. Returns a list of error messages
     * (empty = valid) plus the normalized values ready for
     * JobRepository::saveJob().
     *
     * @param array{name: string, title: string, command: string, args: string, timeout: int, enabled: bool, triggers_time: list<string>, triggers_event: list<string>} $data
     * @param object|null $existing the existing job row (null for create)
     *
     * @return array{errors: list<string>, triggers: list<array{type: string, cron: string, event: string}>, command_type: string}
     */
    public static function validate(array $data, ?object $existing): array {
        $errors = [];

        $allow_key = $existing !== null && $data['name'] === (string) $existing->name;
        if (!JobNaming::isValidJobName($data['name'], $allow_key)) {
            $errors[] = I18N::translate('Job name must be a slug: %1$s, max %2$s characters.', 'a-z, 0-9, "_", "-"', '64');
        }
        if ($data['title'] === '') {
            $errors[] = I18N::translate('Job title must not be empty.');
        }

        $raw_triggers = [];
        foreach ($data['triggers_time'] as $cron) {
            $raw_triggers[] = ['type' => TriggerService::TRIGGER_TIME, 'cron' => $cron];
        }
        foreach ($data['triggers_event'] as $event) {
            $raw_triggers[] = ['type' => TriggerService::TRIGGER_EVENT, 'event' => $event];
        }

        $trigger_result = TriggerService::normalizeTriggers(['triggers' => $raw_triggers]);
        foreach ($trigger_result['errors'] as $error) {
            $errors[] = I18N::translate($error);
        }

        if (mb_strlen($data['title']) > 128) {
            $errors[] = I18N::translate('Job title must be at most %d characters.', 128);
        }
        if (strlen($data['args']) > 255) {
            $errors[] = I18N::translate('Arguments must be at most %d characters.', 255);
        }

        $command_type = CronjobUtils::detectCommandType($data['command']);
        if ($command_type === '') {
            $errors[] = I18N::translate('Command must be a %1$s path or an allowlisted core command.', e('modules_v4/<module>/cli/<script>.php'));
        } else {
            $built = JobRunner::buildArgv([
                'command_type' => $command_type,
                'command'      => $data['command'],
                'args'         => $data['args'],
            ], Webtrees::ROOT_DIR);
            if ($built['error'] !== '') {
                $errors[] = I18N::translate('Invalid command: %s', e($built['error']));
            }
        }

        return [
            'errors'       => $errors,
            'triggers'     => $trigger_result['triggers'],
            'command_type' => $command_type,
        ];
    }

    /**
     * Compute the next-runs preview for each cron row in the form.
     * Returns null for rows with an invalid or empty expression.
     *
     * @param list<string> $triggers_time
     *
     * @return array<int, list<string>|null>
     */
    public static function cronPreviews(array $triggers_time): array {
        $previews = [];
        if (!CronExpressionService::hasCronLibrary()) {
            return $previews;
        }
        foreach ($triggers_time as $i => $cron) {
            if (trim((string) $cron) === '') {
                continue;
            }
            try {
                $previews[$i] = CronExpressionService::upcomingRuns((string) $cron, 3, JobRepository::now());
            } catch (DomainException | RuntimeException) {
                $previews[$i] = null;
            }
        }

        return $previews;
    }
}
