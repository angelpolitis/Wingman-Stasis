<?php
    /**
     * Project Name:    Wingman Stasis - Stats Provider Interface
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Stasis.Interfaces namespace.
    namespace Wingman\Stasis\Interfaces;

    /**
     * Optional capability interface implemented by adapters that can surface native storage-level
     * statistics about the underlying cache backend.
     *
     * File-system adapters (`LocalAdapter`) report statistics by scanning the physical directory
     * tree directly from `Cacher`. In-memory adapters (APCu, Redis, Memcached) have no filesystem
     * to scan but expose their own proprietary stat APIs. Implementing this interface allows them
     * to contribute those native metrics through a common contract.
     *
     * The returned array is adapter-specific in shape but must always include the following keys
     * to guarantee a minimum common API that `Cacher::getStats()` can rely on:
     * - `total_keys`   (int)    Total number of entries currently held in the storage layer.
     * - `total_size`   (string) Human-readable memory/storage footprint (e.g. `"48.7 MB"`).
     *                           Use `"N/A"` when the backend does not expose this figure.
     * - `hits`         (int)    Cumulative read hits since last reset. `0` if unavailable.
     * - `misses`       (int)    Cumulative read misses since last reset. `0` if unavailable.
     * - `adapter`      (string) Short name of the adapter class.
     * - `status`       (string) A human-readable status string (e.g. `"Online"`).
     *
     * Adapters are free to add additional, adapter-specific keys beyond the minimum set.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface StatsProviderInterface {
        /**
         * Returns an associative array of storage-level statistics for this adapter.
         * @return array{total_keys: int, total_size: string, hits: int, misses: int, adapter: string, status: string} The statistics array.
         */
        public function getStats () : array;
    }
?>