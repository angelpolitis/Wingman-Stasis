<?php
    /**
     * Project Name:    Wingman Stasis - Adapter Interface
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Feb 22 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis\Interfaces;

    /**
     * Defines the interface for a caching adapter, which abstracts the underlying storage mechanism for cache files.
     * This allows for flexibility in how cache files are stored and accessed, enabling support for different storage
     * backends (e.g., local filesystem, cloud storage, in-memory storage) without changing the caching logic in the
     * Cacher class.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface AdapterInterface {
        /**
         * Appends content to a cache file at the given path.
         * @param string $path The path to the cache file.
         * @param string $contents The content to append to the cache file.
         * @return bool Whether the append operation was successful.
         */
        public function append (string $path, string $contents) : bool;

        /**
         * Deletes the cache file at the given path.
         * @param string $path The path to the cache file.
         * @return bool Whether the delete operation was successful.
         */
        public function delete (string $path) : bool;


        /**
         * Checks if a cache file exists at the given path.
         * @param string $path The path to the cache file.
         * @return bool Whether the cache file exists.
         */
        public function exists (string $path) : bool;

        /**
         * Lists all cache files under the given path.
         * @param string $path The path to list cache files from.
         * @return string[] An array of cache file paths.
         */
        public function list (string $path) : array;

        /**
         * Reads the content of a cache file from the given path.
         * @param string $path The path to the cache file.
         * @return string The content of the cache file.
         */
        public function read (string $path) : string;

        /**
         * Writes content to a cache file at the given path.
         * @param string $path The path to the cache file.
         * @param string $contents The content to write to the cache file.
         * @return bool Whether the write operation was successful.
         */
        public function write (string $path, string $contents) : bool;
    }
?>