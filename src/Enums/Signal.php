<?php
    /**
     * Project Name:    Wingman Stasis - Signal
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     *
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Stasis.Enums namespace.
    namespace Wingman\Stasis\Enums;

    /**
     * Represents a signal emitted by Stasis during cache lifecycle operations.
     *
     * Each case maps to a dot-notation string identifier consumed by Corvus listeners.
     * Cases can be passed directly to `emit()` — coercion to their string value via `->value` is
     * required when the method expects a plain string.
     *
     * For every signal the target (accessible via `getTargets()` on the received event) is the
     * `Cacher` instance that fired the signal. Payload keys listed under each case are set via
     * named-argument `with()` calls and appear as an associative array inside the dispatcher.
     *
     * @package Wingman\Stasis\Enums
     * @author  Angel Politis <info@angelpolitis.com>
     * @since   1.0
     */
    enum Signal : string {
        // ─── Cache ───────────────────────────────────────────────────────────────

        /**
         * Emitted after the entire cache has been successfully wiped via `clear()`.
         * Payload: *(none)*.
         */
        case CACHE_CLEARED = "stasis.cache.cleared";

        /**
         * Emitted after a garbage-collection pass has completed via `collectGarbage()`.
         * Payload: `stats` (array{files: int, indices: int, dirs: int}).
         */
        case CACHE_COLLECTED = "stasis.cache.collected";

        /**
         * Emitted after the registry has been rebuilt via `rebuildRegistry()`.
         * Payload: `count` (int) — the number of entries indexed.
         */
        case CACHE_REBUILT = "stasis.cache.rebuilt";

        // ─── Counter ─────────────────────────────────────────────────────────────

        /**
         * Emitted after a numeric cache value has been adjusted via `increment()` or `decrement()`.
         * Payload: `key` (string), `delta` (int), `value` (int) — the new counter value.
         */
        case COUNTER_ADJUSTED = "stasis.counter.adjusted";

        // ─── Item ────────────────────────────────────────────────────────────────

        /**
         * Emitted after a cache item has been successfully deleted via `delete()`.
         * Payload: `key` (string).
         */
        case ITEM_DELETED = "stasis.item.deleted";

        /**
         * Emitted after `get()` returns a fresh cached value.
         * Payload: `key` (string), `value` (mixed).
         */
        case ITEM_HIT = "stasis.item.hit";

        /**
         * Emitted after `get()` returns the default value due to a missing or stale entry.
         * Payload: `key` (string).
         */
        case ITEM_MISSED = "stasis.item.missed";

        /**
         * Emitted after a cache item has been successfully stored via `set()`.
         * Payload: `key` (string), `value` (mixed), `ttl` (int), `tags` (array).
         */
        case ITEM_WRITTEN = "stasis.item.written";

        // ─── Items ───────────────────────────────────────────────────────────────

        /**
         * Emitted after a batch deletion has been performed via `deleteMultiple()`.
         * Payload: `keys` (array) — the keys that were successfully deleted, `success` (bool).
         */
        case ITEMS_DELETED = "stasis.items.deleted";

        /**
         * Emitted after one or more cache items have been cleared via `clearByTags()`.
         * Payload: `tags` (array), `count` (int) — the number of items removed.
         */
        case ITEMS_INVALIDATED = "stasis.items.invalidated";
    }
?>