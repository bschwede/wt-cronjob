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
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;

use function array_key_exists;
use function is_object;
use function is_string;

/**
 * Route-triggered events.
 *
 * A complementary, *immediate* detection mechanism to the polling pseudo-events
 * (PseudoEventService): instead of comparing state snapshots on a schedule, it
 * observes the HTTP request itself. When a request to a curated mutating route
 * (keyed by handler class) has been handled successfully, it queues a matching
 * event for the tick's event drain.
 *
 * Why this is safe to run in the per-request middleware (unlike the removed
 * polling detection): it does no heavy work - a free in-memory map lookup, and
 * at most two tiny indexed reads for the rare mapped route - and
 * EventQueue::push() is a plain DB insert in the SAME transaction as the
 * mutation, so the queued event commits or rolls back together with the change
 * (no drift).
 *
 * Gated by a module setting (default off) AND by a listening event-triggered
 * job: an unmapped request costs nothing, and a mapped one writes a row only
 * when something actually consumes it.
 */
final class RouteEventService {

    /** Module-setting name that switches the feature on (stored as '1'/'0'). */
    public const SETTING = 'route_events_enabled';

    /**
     * Curated route -> event map, keyed by the fully-qualified handler class.
     *
     * FQCN *strings* (not ::class) on purpose: if webtrees renames a handler in
     * an update, the entry simply stops matching (the event stops firing) instead
     * of causing a fatal "class not found".
     *
     * @return array<string, array{event: string, payload: list<string>}>
     */
    public static function map(): array {
        return [
            'Fisharebest\Webtrees\Http\RequestHandlers\EditNoteAction' => [
                'event'   => 'edit-note-object',
                'payload' => ['tree', 'xref'],
            ],
        ];
    }

    /**
     * Fire a mapped route event, if applicable. Never throws (callers still wrap
     * it, belt and braces).
     */
    public static function maybeFire(ServerRequestInterface $request, ResponseInterface $response): void {
        $route = $request->getAttribute('route');
        if (!is_object($route) || !isset($route->handler)) {
            return;
        }

        // Free in-memory match: the common (unmapped) request path ends here.
        $entry = self::mapFor((string) $route->handler);
        if ($entry === null) {
            return;
        }
        if (!self::shouldFire($response->getStatusCode())) {
            return;
        }

        // Two tiny indexed reads, only for the rare mapped + successful route.
        if (!self::isEnabled() || !self::hasListener($entry['event'])) {
            return;
        }

        $payload = self::buildPayload(self::flattenAttributes($request), $entry['payload']);
        EventQueue::push($entry['event'], $payload);
    }

    /**
     * The map entry for a handler class, or null when not curated.
     *
     * @return array{event: string, payload: list<string>}|null
     */
    public static function mapFor(string $handler): ?array {
        $map = self::map();

        return $map[$handler] ?? null;
    }

    /**
     * A handler response counts as a successful mutation for 2xx/3xx (a redirect
     * is a normal webtrees success, e.g. redirect to the edited record).
     */
    public static function shouldFire(int $status): bool {
        return $status >= 200 && $status < 400;
    }

    /**
     * Pick the requested keys out of a flat attribute map (pure).
     *
     * @param array<string, string> $flat
     * @param list<string>          $keys
     * @return array<string, string>
     */
    public static function buildPayload(array $flat, array $keys): array {
        $payload = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $flat)) {
                $payload[$key] = $flat[$key];
            }
        }

        return $payload;
    }

    private static function isEnabled(): bool {
        $value = DB::table('module_setting')
            ->where('module_name', '=', CronjobUtils::MODULE_NAME)
            ->where('setting_name', '=', self::SETTING)
            ->value('setting_value');

        return $value === '1' || $value === 'true';
    }

    private static function hasListener(string $event): bool {
        return DB::table('cj_job')
            ->where('enabled', 1)
            ->where('trigger_type', '=', ScheduleService::TRIGGER_EVENT)
            ->where('event_name', '=', $event)
            ->exists();
    }

    /**
     * Flatten the relevant route attributes to JSON-encodable scalars.
     * webtrees-dependent (the Tree object) - not covered by the standalone tests.
     *
     * @return array<string, string>
     */
    private static function flattenAttributes(ServerRequestInterface $request): array {
        $flat = [];

        $tree = $request->getAttribute('tree');
        if ($tree instanceof Tree) {
            $flat['tree']      = (string) $tree->id();
            $flat['tree_name'] = (string) $tree->name();
        }

        $xref = $request->getAttribute('xref');
        if (is_string($xref)) {
            $flat['xref'] = $xref;
        }

        return $flat;
    }
}
