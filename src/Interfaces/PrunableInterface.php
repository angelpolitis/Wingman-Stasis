<?php
    /**
     * Project Name:    Wingman Stasis - Prunable Interface
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
     * Optional capability interface implemented by adapters that can remove empty shard
     * directories left behind after cache item deletions.
     *
     * Only file-based adapters (e.g. `LocalAdapter`) create a physical directory tree and
     * therefore need to clean it up. In-memory adapters (APCu, Redis, Memcached) use flat
     * key namespaces and have no concept of empty directories, so they do not implement
     * this interface.
     *
     * `Cacher` checks for this interface in `collectGarbage()` and `pruneEmptyDirectories()`,
     * replacing the previous `instanceof LocalAdapter` coupling with a proper capability check.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface PrunableInterface {
        /**
         * Recursively removes empty directories within the given root, skipping any directory
         * whose basename appears in the `$excluded` list.
         * @param string $rootDir The absolute path of the root directory to prune.
         * @param string[] $excluded Basenames (not full paths) of directories to skip entirely.
         * @return int The number of directories successfully removed.
         */
        public function pruneEmptyDirectories (string $rootDir, array $excluded = []) : int;
    }
?>