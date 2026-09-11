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

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MessageService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\SiteUser;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Throwable;

use function array_slice;
use function count;
use function explode;
use function implode;
use function strlen;
use function substr;
use function trim;

/**
 * Phase-2 failure notification (§6.2).
 *
 * When a job with `notify` enabled fails, alert the site's administrator
 * users. Delivery goes through webtrees' own MessageService::deliverMessage(),
 * which delivers via the INTERNAL message system and/or e-mail according to
 * EACH recipient's contact-method preference (PREF_CONTACT_METHOD) - so the
 * "who gets it and how" follows the site's own messaging conventions rather
 * than hardcoding a channel. Recipients are the administrator accounts
 * (UserService::administrators()); EmailService::send() itself only accepts
 * user accounts, not free-form addresses, which is why admins are the unit.
 *
 * Runs in the tick (CLI), so there is no request: the message carries no URL
 * and a placeholder IP. A notification failure must never break the tick.
 */
final class NotifyService {

    /**
     * @param object $job the cj_job row (needs: notify, title, name)
     */
    public static function notifyJobFailed(object $job, string $status, int $exit_code, int $duration_ms, string $output, string $started_at): void {
        if ((int) ($job->notify ?? 0) === 0) {
            return;
        }

        try {
            /** @var UserService $user_service */
            $user_service    = Registry::container()->get(UserService::class);
            /** @var MessageService $message_service */
            $message_service = Registry::container()->get(MessageService::class);

            $admins = $user_service->administrators();
            if ($admins->isEmpty()) {
                return;
            }

            $subject = I18N::translate('cronjob: job "%1$s" failed (%2$s)', (string) $job->title, $status);
            $body    = self::buildBody($job, $status, $exit_code, $duration_ms, $output, $started_at);
            $sender  = new SiteUser();

            /** @var UserInterface $admin */
            foreach ($admins as $admin) {
                $message_service->deliverMessage($sender, $admin, $subject, $body, '', '0.0.0.0');
            }
        } catch (Throwable) {
            // A notification problem must never break the tick.
        }
    }

    /**
     * @param object $job
     */
    private static function buildBody(object $job, string $status, int $exit_code, int $duration_ms, string $output, string $started_at): string {
        $lines = [
            I18N::translate('A scheduled cronjob job failed. Details:'),
            '',
            I18N::translate('Job') . ': ' . $job->title . ' (' . $job->name . ')',
            MoreI18N::xlate('Status') . ': ' . $status,
            I18N::translate('Exit code') . ': ' . $exit_code,
            I18N::translate('Duration') . ': ' . $duration_ms . ' ms',
            I18N::translate('Started (UTC)') . ': ' . $started_at,
        ];

        $tail = self::outputTail($output);
        if ($tail !== '') {
            $lines[] = '';
            $lines[] = I18N::translate('Last output') . ':';
            $lines[] = $tail;
        }

        return implode("\n", $lines);
    }

    /**
     * A short, safe tail of the (possibly long) captured output.
     */
    private static function outputTail(string $output, int $max_lines = 8, int $max_chars = 1000): string {
        $output = trim($output);
        if ($output === '') {
            return '';
        }

        $lines = explode("\n", $output);
        if (count($lines) > $max_lines) {
            $lines = array_slice($lines, -$max_lines);
        }
        $tail = implode("\n", $lines);

        if (strlen($tail) > $max_chars) {
            $tail = substr($tail, -$max_chars);
        }

        return $tail;
    }
}
