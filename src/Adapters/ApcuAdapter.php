<?php
    /**
     * Project Name:    Wingman Stasis - APCu Adapter
     * Created by:      Angel Politis
     * Creation Date:   Mar 13 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher.Adapters namespace.
    namespace Wingman\Stasis\Adapters;

    # Import the following classes to the current scope.
    use APCuIterator;
    use Wingman\Stasis\Exceptions\NonNumericValueException;
    use Wingman\Stasis\Exceptions\StorageException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Interfaces\CounterInterface;
    use Wingman\Stasis\Interfaces\LockableInterface;
    use Wingman\Stasis\Interfaces\StatsProviderInterface;

    /**
     * Implements the AdapterInterface using the APCu in-memory cache extension as the storage
     * backend. All "paths" are treated as opaque string keys in the APCu key-value store.
     *
     * Limitations compared to the `LocalAdapter`:
     * - Data is not persisted across PHP-FPM restarts or server reboots.
     * - Shared between all worker processes on a single server; not suitable for multi-server
     *   deployments without an additional synchronisation strategy.
     * - In CLI mode, APCu is disabled by default; set `apc.enable_cli = 1` in `php.ini`.
     *
     * The concept of "directories" is emulated by treating any path that does not match an exact
     * key as a key prefix: `delete()` and `list()` will operate on all keys whose name begins
     * with that prefix.
     * @package Wingman\Stasis\Adapters
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class ApcuAdapter implements AdapterInterface, CounterInterface, LockableInterface, StatsProviderInterface {
        /**
         * Creates a new APCu adapter.
         * @throws StorageException If the APCu extension is not loaded or is not enabled.
         */
        public function __construct () {
            if (!extension_loaded("apcu")) {
                throw new StorageException("The APCu extension is required but not loaded.");
            }

            if (!apcu_enabled()) {
                throw new StorageException("APCu is not enabled. Ensure 'apc.enable_cli = 1' in php.ini for CLI usage.");
            }
        }

        /**
         * Builds the regex pattern used to match keys that share the given path prefix.
         * @param string $path The exact path to build a prefix pattern for.
         * @return string A PCRE pattern anchored at the start of the key.
         */
        private function buildPrefixPattern (string $path) : string {
            return '/^' . preg_quote($path, '/') . '/';
        }

        /**
         * Attempts to acquire an exclusive lock by atomically adding a sentinel key to APCu.
         * `apcu_add()` only succeeds when the key does not already exist, making this operation
         * safe for cross-process use on a single server.
         * @param string $key The logical cache key the lock protects.
         * @param int $ttl Maximum seconds before the lock auto-releases.
         * @return bool Whether the lock was acquired by this process.
         */
        public function acquireLock (string $key, int $ttl = 30) : bool {
            return (bool) apcu_add("__lock__:$key", 1, $ttl);
        }

        /**
         * Atomically adjusts a raw integer counter stored at an APCu key.
         *
         * Uses `apcu_inc()`/`apcu_dec()` which are atomic at the APCu SHM layer, providing
         * genuine cross-process safety on a single server. When the key is absent, `apcu_add()`
         * initialises it to `$delta` (also effectively atomic: only one process wins the `add`).
         * When the key contains a non-numeric value `NonNumericValueException` is thrown.
         * @param string $path  The APCu key (the fully resolved path passed by `Cacher`).
         * @param int    $delta Amount to add (positive) or subtract (negative).
         * @param int    $ttl   TTL in seconds; `0` means no expiry at the APCu layer.
         * @return int The new counter value.
         * @throws NonNumericValueException If the stored value is not numeric.
         */
        public function adjustCounter (string $path, int $delta, int $ttl) : int {
            $ttlValue = $ttl > 0 ? $ttl : 0;

            $result = $delta >= 0
                ? apcu_inc($path, $delta, $success, $ttlValue)
                : apcu_dec($path, abs($delta), $success, $ttlValue);

            if ($result === false) {
                if (apcu_exists($path)) {
                    throw new NonNumericValueException("Cannot perform a counter operation on a non-numeric cache value at \"$path\".");
                }

                apcu_add($path, $delta, $ttlValue);
                return $delta;
            }

            return (int) $result;
        }

        /**
         * Appends content to an existing APCu entry, creating the entry if it does not yet exist.
         * @param string $path The cache key.
         * @param string $contents The content to append.
         * @return bool Whether the operation succeeded.
         */
        public function append (string $path, string $contents) : bool {
            $existing = apcu_fetch($path, $success);
            $combined = $success ? ($existing . $contents) : $contents;
            return (bool) apcu_store($path, $combined);
        }

        /**
         * Deletes an APCu entry or, when no exact key matches, all entries whose key begins with the
         * given path (simulating directory deletion).
         * @param string $path The exact cache key or a directory-style prefix.
         * @return bool Whether the deletion succeeded.
         */
        public function delete (string $path) : bool {
            if (apcu_exists($path)) {
                return (bool) apcu_delete($path);
            }

            $success = true;
            $iterator = new APCuIterator($this->buildPrefixPattern($path));

            foreach ($iterator as $entry) {
                if (!apcu_delete($entry['key'])) {
                    $success = false;
                }
            }

            return $success;
        }

        /**
         * Checks whether an exact APCu key exists.
         * @param string $path The cache key.
         * @return bool Whether the key exists.
         */
        public function exists (string $path) : bool {
            return (bool) apcu_exists($path);
        }

        /**
         * Returns storage-level statistics from APCu using `apcu_cache_info()`.
         * @return array The statistics array.
         */
        public function getStats () : array {
            $info = apcu_cache_info(false);
            $sma = apcu_sma_info();

            $totalMemory = ($sma['num_seg'] ?? 1) * ($sma['seg_size'] ?? 0);
            $usedMemory = $totalMemory - ($sma['avail_mem'] ?? 0);
            $usedMb = $totalMemory > 0 ? round($usedMemory / 1024 / 1024, 2) : 0;

            return [
                "total_keys" => (int) ($info['num_entries'] ?? 0),
                "total_size" => $usedMb . " MB",
                "hits" => (int) ($info['num_hits'] ?? 0),
                "misses" => (int) ($info['num_misses'] ?? 0),
                "adapter" => "ApcuAdapter",
                "status" => "Online",
                "memory_total_mb" => round($totalMemory / 1024 / 1024, 2),
                "memory_free_mb" => round(($sma['avail_mem'] ?? 0) / 1024 / 1024, 2)
            ];
        }

        /**
         * Returns all APCu keys that begin with the given path prefix.
         * @param string $path The key prefix to search under.
         * @return string[] An array of matching cache keys.
         */
        public function list (string $path) : array {
            $keys = [];
            $iterator = new APCuIterator($this->buildPrefixPattern($path));

            foreach ($iterator as $entry) {
                $keys[] = $entry['key'];
            }

            return $keys;
        }

        /**
         * Reads an APCu cache entry and returns its value as a string.
         * Returns an empty string when the key does not exist.
         * @param string $path The cache key.
         * @return string The cached content, or an empty string if the key is absent.
         */
        public function read (string $path) : string {
            $value = apcu_fetch($path, $success);
            return $success ? (string) $value : "";
        }

        /**
         * Releases a previously acquired lock by deleting its APCu sentinel key.
         * @param string $key The logical cache key whose lock should be released.
         * @return bool Whether the key was successfully deleted.
         */
        public function releaseLock (string $key) : bool {
            return (bool) apcu_delete("__lock__:$key");
        }

        /**
         * Stores a string value under the given APCu key.
         * @param string $path The cache key.
         * @param string $contents The content to store.
         * @return bool Whether the write succeeded.
         */
        public function write (string $path, string $contents) : bool {
            return (bool) apcu_store($path, $contents);
        }
    }
?>