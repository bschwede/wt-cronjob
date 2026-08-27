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

use Aura\Router\RouterContainer;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Throwable;

use function array_values;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * Route-triggered events.
 *
 * A complementary, *immediate* detection mechanism to the polling pseudo-events
 * (PseudoEventService): instead of comparing state snapshots on a schedule, it
 * observes the HTTP request itself. When a request to a curated mutating route
 * has been handled successfully, it queues a matching event for the tick's
 * event drain.
 *
 * The map is RULE-BASED (not a hand-curated list) and built once per process
 * from the live webtrees route table:
 *   - path starts with /tree/{tree}/
 *   - POST only (the GET page routes of the same actions are not mapped)
 *   - handler in the webtrees RequestHandlers namespace whose class name
 *     starts with Edit / Delete / Add / Create
 * The event name is the third path segment, namespaced `_route:<segment>`
 * (the §11 naming scheme). When several mapped routes share a segment
 * (e.g. `delete/{xref}` and `delete/{xref}/{fact_id}`), the following path
 * parameters are appended, hyphen-separated (`_route:delete-xref`,
 * `_route:delete-xref-fact_id`). Core updates that add/remove/rename routes
 * are picked up automatically.
 *
 * Why this is safe to run in the per-request middleware (unlike the removed
 * polling detection): it does no heavy work - a free in-memory map lookup, and
 * at most two tiny indexed reads for the rare mapped route - and
 * EventQueue::push() is a plain DB insert in the SAME transaction as the
 * mutation, so the queued event commits or rolls back together with the
 * change (no drift).
 *
 * Gated by a module setting (default off) AND by a listening event-triggered
 * job: an unmapped request costs nothing, and a mapped one writes a row only
 * when something actually consumes it.
 */
final class RouteEventService {

    /** Module-setting name that switches the feature on (stored as '1'/'0'). */
    public const SETTING = 'route_events_enabled';

    /** §11 naming-scheme domain for route-triggered events. */
    public const DOMAIN = '_route';

    /** Path prefix that marks the per-tree editor routes. */
    private const TREE_PREFIX = '/tree/{tree}/';

    /** Handler class-name prefixes that mark a mutating action. */
    private const HANDLER_PREFIXES = ['Edit', 'Delete', 'Add', 'Create'];

    private const HANDLER_NS = 'Fisharebest\Webtrees\Http\RequestHandlers\\';

    /** Per-process map cache (handler class => ['event' => …, 'path' => …]). */
    private static ?array $cached_map = null;

    /**
     * The route -> event map, keyed by the fully-qualified handler class.
     *
     * Built once per process from the live route table (Aura routes); when
     * the router container is not available (e.g. CLI) or nothing matches,
     * a static minimal map is used so the known pilot route keeps working.
     *
     * @return array<string, array{event: string, path: string}>
     */
    public static function map(): array {
        if (self::$cached_map !== null) {
            return self::$cached_map;
        }

        self::$cached_map = self::buildMap(self::collectRouteTriples());

        return self::$cached_map;
    }

    /**
     * The route triples from the live route table: in the web context the
     * container's RouterContainer (the exact table the request matched);
     * otherwise (CLI/tick, e.g. for the catalog sync) the core web route
     * table is built directly.
     *
     * @return list<array{path: string, allows: list<string>, handler: string}>
     */
    private static function collectRouteTriples(): array {
        $collection = null;
        try {
            /** @var RouterContainer $router */
            $router = Registry::container()->get(RouterContainer::class);
            $collection = $router->getCollection();
        } catch (Throwable) {
            try {
                $collection = (new RouterContainer('/'))->getMap();
                (new WebRoutes())->load($collection);
            } catch (Throwable) {
                $collection = null;
            }
        }

        $routes = [];
        if ($collection === null) {
            return $routes;
        }
        foreach ($collection->getRoutes() as $route) {
            $routes[] = [
                'path'    => (string) $route->path,
                'allows'  => array_values((array) ($route->allows ?? [])),
                'handler' => (string) ($route->handler ?? ''),
            ];
        }

        return $routes;
    }

    /**
     * Fire a mapped route event, if applicable. Never throws (callers still
     * wrap it, belt and braces).
     */
    public static function maybeFire(ServerRequestInterface $request, ResponseInterface $response): void {
        $route = $request->getAttribute('route');
        if (!is_object($route) || !$route->handler) {
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

        $payload = self::buildPayload(self::flattenAttributes($request, $route));
        EventQueue::push($entry['event'], $payload);
    }

    /**
     * The map entry for a handler class, or null when not mapped.
     *
     * @return array{event: string, path: string}|null
     */
    public static function mapFor(string $handler): ?array {
        $map = self::map();

        return $map[$handler] ?? null;
    }

    /**
     * Pure map builder (standalone-testable): derives the curated route-event
     * map from route triples. See the class docblock for the rules.
     *
     * @param list<array{path: string, allows: list<string>, handler: string}> $routes
     *
     * @return array<string, array{event: string, path: string}>
     */
    public static function buildMap(array $routes): array {
        $by_segment = [];
        $mapped     = [];
        foreach ($routes as $route) {
            if (!self::isMappedRoute((string) $route['path'], (array) $route['allows'], (string) $route['handler'])) {
                continue;
            }
            $segment = self::segment((string) $route['path']);
            if ($segment === '') {
                continue;
            }
            $by_segment[$segment][] = $route;
            $mapped[]               = $route;
        }

        $map = [];
        foreach ($mapped as $route) {
            $path    = (string) $route['path'];
            $segment = self::segment($path);
            $event   = self::DOMAIN . ':' . $segment;
            if (count($by_segment[$segment]) > 1) {
                $params = self::trailingParams($path);
                if ($params !== []) {
                    $event .= '-' . implode('-', $params);
                }
            }
            $map[(string) $route['handler']] = [
                'event' => $event,
                'path'  => $path,
            ];
        }

        // No route table available (CLI) or nothing matched - keep the known
        // pilot route working in any context.
        if ($map === []) {
            return self::fallbackMap();
        }

        return $map;
    }

    /**
     * A handler response counts as a successful mutation for 2xx/3xx (a
     * redirect is a normal webtrees success, e.g. redirect to the edited
     * record).
     */
    public static function shouldFire(int $status): bool {
        return $status >= 200 && $status < 400;
    }

    /**
     * Keep the JSON-encodable scalars of a flattened attribute map (pure).
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, string|int|float|bool>
     */
    public static function buildPayload(array $flat): array {
        $payload = [];
        foreach ($flat as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $payload[(string) $key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Whether the given route triple is a mapped mutating route.
     *
     * @param list<string> $allows
     */
    private static function isMappedRoute(string $path, array $allows, string $handler): bool {
        if (!str_starts_with($path, self::TREE_PREFIX)) {
            return false;
        }
        if ($allows !== ['POST']) {
            return false;
        }
        if (!str_starts_with($handler, self::HANDLER_NS)) {
            return false;
        }
        $class = substr($handler, strlen(self::HANDLER_NS));
        foreach (self::HANDLER_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first path segment after /tree/{tree}/ (the third path component),
     * or '' when missing or not a plain slug.
     */
    private static function segment(string $path): string {
        $rest  = substr($path, strlen(self::TREE_PREFIX));
        $slash = strpos($rest, '/');
        $seg   = $slash === false ? $rest : substr($rest, 0, $slash);

        return preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $seg) === 1 ? $seg : '';
    }

    /**
     * The parameter names following the first segment, e.g.
     * '/delete/{xref}/{fact_id}' -> ['xref', 'fact_id']; optional segments
     * like '{/fact_id}' count as well.
     *
     * @return list<string>
     */
    private static function trailingParams(string $path): array {
        $rest  = substr($path, strlen(self::TREE_PREFIX));
        $slash = strpos($rest, '/');
        $tail  = $slash === false ? '' : substr($rest, $slash);

        preg_match_all('/\{\/?([a-z0-9_]+)\}/i', $tail, $matches);

        return array_values($matches[1] ?? []);
    }

    /**
     * Flattens the Tree attribute and the matched route params to a map.
     * webtrees-dependent (the Tree object) - not covered by the standalone
     * tests.
     *
     * @return array<string, mixed>
     */
    private static function flattenAttributes(ServerRequestInterface $request, object $route): array {
        $flat = [];

        $tree = $request->getAttribute('tree');
        if ($tree instanceof Tree) {
            $flat['tree']      = (string) $tree->id();
            $flat['tree_name'] = (string) $tree->name();
        }

        foreach ((array) ($route->attributes ?? []) as $key => $value) {
            if ((string) $key === 'tree') {
                continue; // expanded above
            }
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $flat[(string) $key] = $value;
            }
        }

        return $flat;
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
     * Static fallback map (used when the route table is not available).
     *
     * @return array<string, array{event: string, path: string}>
     */
    private static function fallbackMap(): array {
        return [
            'Fisharebest\Webtrees\Http\RequestHandlers\EditNoteAction' => [
                'event' => self::DOMAIN . ':edit-note-object',
                'path'  => self::TREE_PREFIX . 'edit-note-object/{xref}',
            ],
        ];
    }
}
