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

use function preg_match;
use function strlen;
use function strpos;
use function substr;

/**
 * Job and event name validation, plus slug generation for duplicated
 * jobs. Pure - standalone-testable without the webtrees core or a
 * database (the default existence check in uniqueCopySlug() is the only
 * DB touchpoint; tests inject their own callable).
 */
final class JobNaming {

    public const NAME_MAXLEN = 64;
    private const COPY_SUFFIX = '-copy'; // 5 chars
    private const NAME_COPY_MAXLEN = 59;
    private const RE_ALLOWED_CHARS = '[a-z0-9_\-]';
    private const RE_STD_NAME = '[a-z0-9]' . self::RE_ALLOWED_CHARS . '{0,63}';
    private const RE_PREFIX_NAME = '[a-z0-9_]' . self::RE_ALLOWED_CHARS . '{0,63}';
    

    /**
     * Whether a bare slug (a manifest-provided name that will later be
     * prefixed with a module/domain) is valid.
     *
     * @param bool $allow_leading_punct when true, the first character may
     *        be an underscore or dash (job spec names); when false, the
     *        first character must be alphanumeric (event spec names).
     */
    public static function isValidBareSlug(string $name, bool $allow_leading_punct = false): bool {
        if ($name === '' || strlen($name) > self::NAME_MAXLEN) {
            return false;
        }

        if ($allow_leading_punct) {
            return preg_match('/^' . self::RE_ALLOWED_CHARS . '+$/', $name) === 1;
        }

        return preg_match('/^[a-z0-9]' . self::RE_ALLOWED_CHARS . '*$/', $name) === 1;
    }

    /**
     * Next free slug for a duplicated job: <base>-copy, <base>-copy2, …
     * The base is truncated to 59 chars so the suffix still fits into the
     * 64-char cj_job.name column.
     *
     * @param (callable(string): bool)|null $exists returns true if $slug is taken (default: cj_job DB check)
     */
    public static function uniqueCopySlug(string $base, ?callable $exists = null): string {
        $exists ??= static fn (string $slug): bool => JobRepository::nameExists($slug);

        $base = substr($base, 0, self::NAME_COPY_MAXLEN);
        $n    = 1;

        do {
            $slug = $n === 1 ? $base . self::COPY_SUFFIX : $base . self::COPY_SUFFIX . (string) $n;
            $n++;
        } while ($exists($slug) && $n < 1000);

        return $slug;
    }

    /**
     * Name base for a duplicated job: module-offered jobs are keyed
     * <module>:<name>, but the colon cannot be entered in the form (slug
     * pattern), so the prefix is stripped for the copy. Anything else is
     * returned unchanged.
     */
    public static function copySlugBase(string $name): string {
        if (preg_match('/^' . self::RE_ALLOWED_CHARS . '+:/', $name) === 1) {
            return substr($name, strpos($name, ':') + 1);
        }

        return $name;
    }

    /**
     * Whether a job name (slug) is valid.
     *
     * A plain slug is always valid. A module-offered key (`<module>:<slug>`) is
     * accepted only when $allow_module_key is set - callers pass it true solely to
     * preserve an existing offered name on update, never to create or retarget one.
     * The whole name (colon included) must fit the 64-char cj_job.name column, so
     * the two parts are not each allowed up to 64 characters.
     */
    public static function isValidJobName(string $name, bool $allow_module_key = false): bool {
        if (preg_match('/^' . self::RE_STD_NAME . '$/', $name) === 1) {
            return true;
        }

        return $allow_module_key
            && strlen($name) <= self::NAME_MAXLEN
            && preg_match('/^' . self::RE_STD_NAME . ':' . self::RE_STD_NAME . '$/', $name) === 1;
    }

    /**
     * Whether an event name is valid under the event naming scheme (analogous
     * to job names): a plain slug (custom / webhook events) or a namespaced
     * `<domain>:<slug>` (route events `_route:*`, built-in pseudo-events
     * `cronjob:*`, module-announced events `<module>:*`). Unlike job names,
     * the domain part may start with an underscore (reserved domains like
     * `_route`). The whole name must fit the 64-char cj_event.event_name
     * column.
     */
    public static function isValidEventName(string $name): bool {
        if (preg_match('/^' . self::RE_STD_NAME . '$/', $name) === 1) {
            return true;
        }

        return strlen($name) <= 64
            && preg_match('/^' . self::RE_PREFIX_NAME . ':' . self::RE_STD_NAME . '$/', $name) === 1;
    }
}
