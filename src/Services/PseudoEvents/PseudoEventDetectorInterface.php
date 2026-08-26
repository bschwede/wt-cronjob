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
 * A state-polling pseudo-event detector (phase 2, §6.1 Stufe B).
 *
 * webtrees has no event bus, so "events" for core actions (a GEDCOM import,
 * new media, a new user) are approximated by polling state on a schedule and
 * firing an event when the state transitions. Each detector knows how to read
 * its own slice of state (currentState()) and how to decide that a transition
 * worth firing occurred (compare()). The PseudoEventService persists each
 * detector's state between runs and queues the emitted events for the tick.
 */
interface PseudoEventDetectorInterface {

    /**
     * The event slug this detector emits (matches cj_job.event_name).
     */
    public function eventName(): string;

    /**
     * Human label for the admin UI.
     */
    public function label(): string;

    /**
     * Read the current state of this detector's slice.
     *
     * Environment-specific (queries the core tables / stats data files); not
     * exercised by the standalone tests.
     *
     * @return mixed a JSON-encodable snapshot of the current state
     */
    public function currentState(): mixed;

    /**
     * Decide whether the transition from $last_state to $current_state should
     * fire the event. Pure and side-effect free (standalone-testable).
     *
     * On the very first run $last_state is null: the detector must establish
     * a baseline (detected=false) rather than fire on the initial state.
     *
     * @param mixed               $last_state  the persisted state, or null on first run
     * @param mixed               $current_state
     * @return array{detected: bool, state: mixed, payload: array<string, mixed>}
     *   - detected: true when a transition that should fire the event occurred
     *   - state:    the value to persist for the next run (always $current_state)
     *   - payload:  JSON payload attached to the queued event (meaningful when detected)
     */
    public function compare(mixed $last_state, mixed $current_state): array;
}
