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
use Fisharebest\Webtrees\Http\Routing\RouteCollection;
use Fisharebest\Webtrees\Http\Routes\WebRoutes;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\Functions;
use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Throwable;

use function array_key_exists;
use function array_values;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;
use function strtoupper;

/**
 * Route-triggered events.
 *
 * A complementary, *immediate* detection mechanism to the polling pseudo-events
 * (PseudoEventService): instead of comparing state snapshots on a schedule, it
 * observes the HTTP request itself. When a request to a curated mutating route
 * has been handled successfully, it queues a matching event for the tick's
 * event drain.
 *
 * The map is built once per process from the live webtrees route table by
 * two rules (a route is mapped when it matches either):
 *   - record routes (rule-based, auto-picked-up by core updates):
 *       path starts with /tree/{tree}/, contains {xref} (record specificity),
 *       POST only (2.2.6; in 2.3 the route carries no method and the POST
 *       check moves to fire-time), handler in the webtrees request-handler
 *       namespace (RequestHandlers in 2.2.6, Controllers in 2.3) whose class
 *       name starts with Edit / Delete / Add / Create / Link / Paste /
 *       Reorder / Pending. The event name is the third path segment, namespaced
 *       `_route:<segment>` (the §11 naming scheme). When several mapped
 *       routes share a segment (e.g. `delete/{xref}` and
 *       `delete/{xref}/{fact_id}`), the following path parameters are
 *       appended, hyphen-separated (`_route:delete-xref`,
 *       `_route:delete-xref-fact_id`).
 *   - tree-level routes (curated allowlist, TREE_LEVEL_ROUTES):
 *       tree-wide mutations that have no record parameter in the URL (import,
 *       load, merge, renumber, search-replace, data-fix, bulk accept/reject,
 *       change-family-members). Each allowlist entry carries its explicit
 *       event name.
 *
 * Breaking change vs. the original rule: record routes without {xref} in the
 * URL (all create-* routes, add-unlinked-individual) are no longer mapped -
 * jobs listening to those events must be re-pointed (the admin table marks
 * such orphaned events, see orphanedEvents()).
 *
 * Why this is safe to run in the per-request middleware (unlike the removed
 * polling detection): it does no heavy work - a free in-memory map lookup, and
 * at most three tiny indexed reads for the rare mapped route (the module
 * setting, the listener, and - on record routes - the change-table flag) -
 * and EventQueue::push() is a plain DB insert in the SAME transaction as the
 * mutation, so the queued event commits or rolls back together with the
 * change (no drift).
 *
 * Record-route payloads additionally carry change_pending (bool): whether the
 * change is still pending in the change table after this request (the user's
 * auto-accept preference is off) or already applied (auto-accept on). A
 * pending change is applied later by an admin accepting it - which fires its
 * own events (_route:accept*, _route:reject*). Tree-level routes write
 * directly (no change rows) and carry no such flag.
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

    /** Path placeholder that marks a record-specific route. */
    private const XREF = '{xref}';

    /** Handler class-name prefixes that mark a mutating action. */
    private const HANDLER_PREFIXES = ['Edit', 'Delete', 'Add', 'Create', 'Link', 'Paste', 'Reorder', 'Pending'];

    /**
     * Tree-level mutating routes without a record parameter (curated
     * allowlist): path relative to /tree/{tree}/ => explicit event name.
     *
     * These are the significant tree-wide mutations that no rule can capture
     * (they carry no {xref}), e.g. the chunked-import continuation (load) and
     * the data fixes. Exact full-path matching on purpose: it keeps the
     * no-op DataFixSelect (POST /data-fix) out while taking in
     * DataFixUpdate / DataFixUpdateAll.
     *
     * @var array<string, string>
     */
    private const TREE_LEVEL_ROUTES = [
        'import'                         => '_route:import',
        'load'                           => '_route:load',
        'merge-step1'                    => '_route:merge-step1',
        'merge-step2'                    => '_route:merge-step2',
        'search-replace'                 => '_route:search-replace',
        'renumber'                       => '_route:renumber',
        'data-fix/{data_fix}/update'     => '_route:data-fix-update',
        'data-fix/{data_fix}/update-all' => '_route:data-fix-update-all',
        'accept'                         => '_route:accept',
        'reject'                         => '_route:reject',
        'change-family-members'          => '_route:change-family-members',
    ];

    /** Core request-handler namespaces: 2.2.6 `RequestHandlers`, 2.3 `Controllers`. */
    private const HANDLER_NS_226 = 'Fisharebest\Webtrees\Http\RequestHandlers\\';
    private const HANDLER_NS_23 = 'Fisharebest\Webtrees\Http\Controllers\\';

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
     * container's route table (2.2.6 Aura RouterContainer / 2.3 RouteCollection,
     * the exact table the request matched); otherwise (CLI/tick, e.g. for the
     * catalog sync) the core web route table is built directly. 2.3 routes carry
     * no HTTP method, so `allows` is empty there (decided at fire-time).
     *
     * @return list<array{path: string, allows: list<string>, handler: string}>
     */
    private static function collectRouteTriples(): array {
        if (Functions::wtIsAtLeast2_3()) {
            try {
                $collection = Registry::container()->get(RouteCollection::class);
            } catch (Throwable) {
                try {
                    $collection = new RouteCollection();
                    (new WebRoutes())->load($collection);
                } catch (Throwable) {
                    $collection = null;
                }
            }

            $routes = [];
            foreach (($collection?->all() ?? []) as $route) {
                $routes[] = [
                    'path'    => (string) $route->url,
                    'allows'  => [],
                    'handler' => (string) $route->controller,
                ];
            }

            return $routes;
        }

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
        if (!is_object($route)) {
            return;
        }

        $handler = self::routeHandler($route);
        if ($handler === '') {
            return;
        }

        // Free in-memory match: the common (unmapped) request path ends here.
        $entry = self::mapFor($handler);
        if ($entry === null) {
            return;
        }
        // A successful POST mutation (in 2.3 the route also serves the GET form-load).
        if (!self::shouldFire($response->getStatusCode(), $request->getMethod())) {
            return;
        }

        // Tiny indexed reads only, and only for the rare mapped + successful
        // route (the module setting and the listener gate below).
        if (!self::isEnabled() || !self::hasListener($entry['event'])) {
            return;
        }

        $flat = self::flattenAttributes($request, $route);

        // Record routes carry the target xref: whether the change is still
        // pending in the change table after this request (auto-accept off) or
        // already applied (auto-accept on). One more tiny indexed read - and
        // it runs in the SAME transaction, so it sees the request's final
        // state. Tree-level routes carry no xref and no change rows.
        if (isset($flat['tree'], $flat['xref'])) {
            $flat['change_pending'] = DB::table('change')
                ->where('gedcom_id', '=', (int) $flat['tree'])
                ->where('xref', '=', (string) $flat['xref'])
                ->where('status', '=', 'pending')
                ->exists();
        }

        EventQueue::push($entry['event'], self::buildPayload($flat));
    }

    /**
     * The route's handler FQCN: 2.2.6 Aura `handler`, 2.3 `controller`.
     */
    private static function routeHandler(object $route): string {
        return Functions::wtIsAtLeast2_3()
            ? (string) ($route->controller ?? '')
            : (string) ($route->handler ?? '');
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
     * The given event names that look like route events (_route:*) but have
     * no counterpart in the live map any more - e.g. because a core update
     * removed or renamed the route, or because this module changed its
     * mapping rules. Used by the admin table to flag orphaned job triggers.
     * Pure apart from the (per-process cached) map.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function orphanedEvents(array $names): array {
        $known = [];
        foreach (self::map() as $entry) {
            $known[$entry['event']] = true;
        }

        $orphaned = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($name !== '' && str_starts_with($name, self::DOMAIN . ':') && !isset($known[$name])) {
                $orphaned[] = $name;
            }
        }

        return $orphaned;
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
            // Tree-level allowlist routes carry an explicit event name and do
            // not take part in the segment collision logic below.
            if (self::treeLevelEvent((string) $route['path']) !== null) {
                $mapped[] = $route;
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
            $path  = (string) $route['path'];
            $event = self::treeLevelEvent($path);
            if ($event === null) {
                $segment = self::segment($path);
                $event   = self::DOMAIN . ':' . $segment;
                if (count($by_segment[$segment]) > 1) {
                    $params = self::trailingParams($path);
                    if ($params !== []) {
                        $event .= '-' . implode('-', $params);
                    }
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
     * A handler response counts as a successful mutation: 2xx/3xx (a redirect
     * is a normal webtrees success, e.g. redirect to the edited record) AND a
     * POST request (in 2.3 the same route serves GET form-load and POST submit;
     * only the submit is a mutation).
     */
    public static function shouldFire(int $status, string $method = 'POST'): bool {
        return $status >= 200 && $status < 400 && strtoupper($method) === 'POST';
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
     * The bare handler class name without the core request-handler namespace
     * (2.2.6 `RequestHandlers` / 2.3 `Controllers`), or null when the handler
     * is not a core request handler.
     */
    private static function handlerClass(string $handler): ?string {
        if (str_starts_with($handler, self::HANDLER_NS_226)) {
            return substr($handler, strlen(self::HANDLER_NS_226));
        }
        if (str_starts_with($handler, self::HANDLER_NS_23)) {
            return substr($handler, strlen(self::HANDLER_NS_23));
        }

        return null;
    }

    /**
     * Whether the given route triple is a mapped mutating route: a
     * record-specific route (see the class docblock) or a curated
     * tree-level allowlist route.
     *
     * @param list<string> $allows
     */
    private static function isMappedRoute(string $path, array $allows, string $handler): bool {
        // POST-only (2.2.6) or method-unknown (2.3: no per-route method; the
        // mutating POST is enforced at fire-time in shouldFire()).
        if ($allows !== ['POST'] && $allows !== []) {
            return false;
        }
        $class = self::handlerClass($handler);
        if ($class === null) {
            return false;
        }
        $tail = self::treeTail($path);
        if ($tail === '') {
            return false;
        }
        if (array_key_exists($tail, self::TREE_LEVEL_ROUTES)) {
            return true;
        }
        if (!str_contains($tail, self::XREF)) {
            return false;
        }
        foreach (self::HANDLER_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The part of the path after the tree prefix, or '' when the path does
     * not start with it.
     */
    private static function treeTail(string $path): string {
        if (!str_starts_with($path, self::TREE_PREFIX)) {
            return '';
        }

        return substr($path, strlen(self::TREE_PREFIX));
    }

    /**
     * The explicit event name of a tree-level allowlist route, or null when
     * the path is not in the allowlist.
     */
    private static function treeLevelEvent(string $path): ?string {
        $tail = self::treeTail($path);
        if ($tail === '') {
            return null;
        }

        return self::TREE_LEVEL_ROUTES[$tail] ?? null;
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
     * The payload key names a mapped route would produce, derived purely from
     * the route path (standalone-testable, no webtrees dependency).
     *
     * Mirrors flattenAttributes() + maybeFire(): the `{tree}` placeholder
     * expands to `tree` and `tree_name` (the Tree object contributes id +
     * name); every other `{param}` placeholder contributes its own name; keys
     * are in the order the placeholders appear in the path. Tree-scoped paths
     * carrying a `{xref}` additionally end with `change_pending` (the
     * change-table flag, see maybeFire()).
     *
     * @return list<string>
     */
    public static function payloadKeys(string $path): array {
        $keys = [];
        if (preg_match_all('/\{\/?([a-z0-9_]+)\}/i', $path, $matches) > 0) {
            foreach ($matches[1] as $param) {
                if ($param === 'tree') {
                    $keys[] = 'tree';
                    $keys[] = 'tree_name';
                } else {
                    $keys[] = $param;
                }
            }
        }
        if (str_contains($path, self::TREE_PREFIX) && str_contains($path, self::XREF)) {
            $keys[] = 'change_pending';
        }

        return $keys;
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

        foreach (Functions::routeParams($route, $request) as $key => $value) {
            if ((string) $key === 'tree') {
                continue; // expanded above
            }
            $flat[(string) $key] = $value;
        }

        return $flat;
    }

    /**
     * Is the route-events feature enabled (module setting)?
     */
    public static function isEnabled(): bool {
        $value = DB::table('module_setting')
            ->where('module_name', '=', CronjobUtils::MODULE_NAME)
            ->where('setting_name', '=', self::SETTING)
            ->value('setting_value');

        return $value === '1' || $value === 'true';
    }

    private static function hasListener(string $event): bool {
        return DB::table('cj_job')
            ->join('cj_job_trigger', 'cj_job_trigger.job_id', '=', 'cj_job.id')
            ->where('cj_job.enabled', 1)
            ->where('cj_job_trigger.trigger_type', '=', ScheduleService::TRIGGER_EVENT)
            ->where('cj_job_trigger.event_name', '=', $event)
            ->exists();
    }

    /**
     * Static fallback map (used when the route table is not available).
     *
     * @return array<string, array{event: string, path: string}>
     */
    private static function fallbackMap(): array {
        return [
            ClassName::get(ClassName::EDIT_NOTE_ACTION) => [
                'event' => self::DOMAIN . ':edit-note-object',
                'path'  => self::TREE_PREFIX . 'edit-note-object/{xref}',
            ],
        ];
    }
}
