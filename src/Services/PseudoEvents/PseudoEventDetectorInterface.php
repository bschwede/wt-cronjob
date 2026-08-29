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

namespace Schwendinger\Webtrees\Module\Cronjob\Services\PseudoEvents;

/**
 * A state-polling pseudo-event detector.
 *
 * webtrees has no event bus, so "events" for core actions (logins, edits,
 * errors, searches) are approximated by polling state on a schedule and
 * firing events when a transition is observed. Each detector knows how to read
 * its own slice of state (currentState()) and how to decide which events a
 * transition fires (compare()). The PseudoEventService persists each detector's
 * state between runs and queues the emitted events for the tick.
 */
interface PseudoEventDetectorInterface {

    /**
     * The event slug this detector emits (matches cj_job.event_name).
     */
    public function eventName(): string;

    /**
     * Human description for the event catalog: when this event fires.
     * A source string (translation key) - the catalog stores it untranslated
     * and renders it through I18N (see EventCatalogService).
     */
    public function description(): string;

    /**
     * The payload key names this detector's events carry (event catalog).
     * Same convention as RouteEventService::payloadKeys(): a plain list;
     * optional keys are documented, not marked.
     *
     * @return list<string>
     */
    public function payloadKeys(): array;

    /**
     * Read the current state of this detector's slice, given the persisted
     * state of the previous run (the detector may query incrementally from it).
     *
     * Environment-specific (queries the core tables); not exercised by the
     * standalone tests.
     *
     * @param mixed $last_state the persisted state, or null on the very first run
     *
     * @return mixed a JSON-encodable snapshot of the current state
     */
    public function currentState(mixed $last_state): mixed;

    /**
     * Decide which events the transition from $last_state to $current_state
     * fires. Pure and side-effect free (standalone-testable).
     *
     * On the very first run $last_state is null: the detector must establish
     * a baseline (no events) rather than fire on the initial state.
     *
     * @param mixed               $last_state  the persisted state, or null on first run
     * @param mixed               $current_state
     * @return array{state: mixed, events: list<array<string, mixed>>}
     *   - state:  the value to persist for the next run
     *   - events: one payload per event to queue (every event is named
     *             eventName()); an empty list = nothing detected
     */
    public function compare(mixed $last_state, mixed $current_state): array;
}
