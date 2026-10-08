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

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Fisharebest\Webtrees\I18N;
use RuntimeException;
use Schwendinger\Webtrees\Helpers\MoreI18N;

use function class_exists;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function preg_split;
use function str_pad;
use function strpos;
use function trim;

use const STR_PAD_LEFT;

/**
 * Cron-expression parsing, evaluation and human-readable descriptions.
 *
 * All cron evaluation and all stored timestamps use UTC - the same basis
 * the webtrees core uses (Webtrees::bootstrap() sets the default timezone
 * to UTC), so tick and admin UI always agree.
 */
final class CronExpressionService {

    public const TIMEZONE = 'UTC';

    /**
     * Is the bundled cron-expression library present (vendor fetched)?
     */
    public static function hasCronLibrary(): bool {
        return class_exists('Cron\\CronExpression');
    }

    public static function cronLibraryMissingMessage(): string {
        return 'Cron library missing: fetch dragonmantank/cron-expression into '
            . 'modules_v4/cronjob/vendor/dragonmantank/cron-expression/ '
            . '(see README.md, section "Bundled dependency").';
    }

    /**
     * Parse a cron expression (throws on invalid input).
     *
     * The library (v3.6.0) throws InvalidArgumentException for invalid
     * expressions - normalized to DomainException here so all callers
     * share one catch type. The timezone is NOT a factory parameter in
     * this version; it is passed explicitly to getNextRunDate() below.
     *
     * @throws DomainException|RuntimeException
     */
    public static function parse(string $cron): CronExpression {
        if (!self::hasCronLibrary()) {
            throw new RuntimeException(self::cronLibraryMissingMessage());
        }
        try {
            return CronExpression::factory($cron);
        } catch (\InvalidArgumentException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws DomainException|RuntimeException
     */
    public static function validateCron(string $cron): bool {
        self::parse($cron);

        return true;
    }

    /**
     * Next run after $from (UTC 'Y-m-d H:i:s'), formatted the same way.
     *
     * @throws DomainException|RuntimeException
     */
    public static function nextRun(string $cron, string $from): string {
        $dt = self::parse($cron)
            ->getNextRunDate(new DateTimeImmutable($from, new DateTimeZone(self::TIMEZONE)), 0, false, self::TIMEZONE);

        return $dt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    /**
     * The next $count runs after $from (for the form preview).
     *
     * @return list<string>
     *
     * @throws DomainException|RuntimeException
     */
    public static function upcomingRuns(string $cron, int $count, string $from): array {
        $runs   = [];
        $cursor = new DateTimeImmutable($from, new DateTimeZone(self::TIMEZONE));
        $cron   = self::parse($cron);

        for ($i = 0; $i < max(1, $count); $i++) {
            $cursor = $cron->getNextRunDate($cursor, 0, false, self::TIMEZONE);
            $runs[] = $cursor->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
        }

        return $runs;
    }

    /**
     * A human-readable description of a common 5-field cron expression (UI add-on).
     *
     * Handles the patterns that maintenance jobs actually use; anything it cannot
     * express confidently is returned unchanged (the raw cron), so the UI can show
     * the human form only when it differs. All user-facing phrases go through
     * I18N::translate(); it is standalone-testable with a trivial I18N shim that
     * returns sprintf(original, ...args) - the same result webtrees yields when no
     * translation is loaded. Always shown next to the exact cron string.
     */
    public static function humanizeCron(string $cron): string {
        $cron = trim($cron);
        if ($cron === '') {
            return '';
        }

        // Standard macros (the same set the cron library accepts).
        $macros = [
            '@minutely'  => I18N::plural('every minute', 'every %1$d minutes', 1, 1),
            '@minute'    => I18N::plural('every minute', 'every %1$d minutes', 1, 1),
            '@hourly'    => I18N::translate('hourly'),
            '@daily'     => I18N::translate('daily at %1$s', '00:00'),
            '@midnight'  => I18N::translate('daily at %1$s', '00:00'),
            '@weekly'    => I18N::translate('weekly on Sunday at 00:00'),
            '@monthly'   => I18N::translate('on day %1$d of each month at %2$s', '1', '00:00'),
            '@yearly'    => I18N::translate('yearly on January 1 at 00:00'),
            '@annually'  => I18N::translate('yearly on January 1 at 00:00'),
        ];
        if (isset($macros[$cron])) {
            return $macros[$cron];
        }

        $fields = preg_split('/\s+/', $cron);
        if (count($fields) !== 5) {
            return $cron; // seconds field, 7-field, etc. - not handled
        }
        [$min, $hour, $dom, $mon, $dow] = $fields;

        // "every minute"
        if ($min === '*' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            return I18N::plural('every minute', 'every %1$d minutes', 1, 1);
        }
        // "every N minutes" (plural form chosen by the locale's CLDR rules)
        if (preg_match('/^\*\/(\d+)$/', $min, $m) === 1 && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $n = (int) $m[1];

            return I18N::plural('every minute', 'every %1$d minutes', $n, $n);
        }
        // "hourly" (minute 0, every hour) or "every hour at :MM"
        if (self::isCronInt($min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $n = (int) $min;

            return $n === 0 ? I18N::translate('hourly') : I18N::translate('every hour at minute %1$d', $n);
        }
        // "daily at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
            return I18N::translate('daily at %1$s', self::time2((int) $hour, (int) $min));
        }
        // "on <weekday> at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && $dom === '*' && $mon === '*' && self::isDow($dow)) {
            return I18N::translate('on %1$s at %2$s', self::dowNames($dow), self::time2((int) $hour, (int) $min));
        }
        // "on day D of each month at HH:MM"
        if (self::isCronInt($min) && self::isCronInt($hour) && self::isCronInt($dom) && $mon === '*' && $dow === '*') {
            return I18N::translate('on day %1$d of each month at %2$s', (int) $dom, self::time2((int) $hour, (int) $min));
        }

        return $cron; // no confident human form - show the raw expression
    }

    /**
     * A field is a single 0-59 / 0-23 style integer.
     */
    private static function isCronInt(string $field): bool {
        return preg_match('/^\d{1,2}$/', $field) === 1;
    }

    /**
     * A day-of-week field that is a number or a list/range of numbers.
     */
    private static function isDow(string $dow): bool {
        if (in_array($dow, ['*'], true)) {
            return false;
        }

        return preg_match('/^(\d{1,2})(-?\d{1,2})?(,(\d{1,2})(-?\d{1,2})?)*$/', $dow) === 1;
    }

    /**
     * HH:MM, zero-padded.
     */
    private static function time2(int $hour, int $minute): string {
        return str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Human names for a day-of-week field: "1-5" -> "Monday to Friday",
     * "0,6" -> "Sunday and Saturday", "1" -> "Monday" (all translatable).
     */
    private static function dowNames(string $dow): string {
        $names = [
            MoreI18N::xlate('Sunday'),
            MoreI18N::xlate('Monday'),
            MoreI18N::xlate('Tuesday'),
            MoreI18N::xlate('Wednesday'),
            MoreI18N::xlate('Thursday'),
            MoreI18N::xlate('Friday'),
            MoreI18N::xlate('Saturday'),
        ];
        $name  = fn (int $d): string => $names[(((int) $d) % 7 + 7) % 7];

        $parts = [];
        foreach (explode(',', $dow) as $chunk) {
            if (strpos($chunk, '-') !== false) {
                [$a, $b] = explode('-', $chunk, 2);
                $parts[] = I18N::translate('%1$s to %2$s', $name((int) $a), $name((int) $b));
            } else {
                $parts[] = $name((int) $chunk);
            }
        }

        if (count($parts) === 2) {
            return I18N::translate('%1$s and %2$s', $parts[0], $parts[1]);
        }

        return implode(', ', $parts);
    }
}
