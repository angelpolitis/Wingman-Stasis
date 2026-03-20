<?php
    /**
     * Project Name:    Wingman Stasis - Memcached Adapter
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
    use Memcached;
    use Wingman\Stasis\Exceptions\StorageException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Interfaces\LockableInterface;
    use Wingman\Stasis\Interfaces\StatsProviderInterface;

    /**
     * Implements the AdapterInterface using a Memcached server cluster as the storage backend.
     * Requires the PHP `ext-memcached` extension and a pre-configured `Memcached` instance with at
     * least one server already added via `Memcached::addServer()` / `Memcached::addServers()`.
     *
     * All "paths" are stored as string keys prefixed with the value passed to the constructor.
     * Memcached's key namespace is flat, so the concept of "directories" is emulated: `delete()`
     * and `list()` treat any path that does not resolve to an exact key as a key prefix, locating
     * matches via a dedicated prefix index maintained in a separate Memcached key.
     *
     * Limitations compared to `LocalAdapter`:
     * - Data is volatile; it is lost on server restart or when the LRU eviction policy removes it.
     * - Memcached does not support key enumeration natively. This adapter maintains a per-prefix
     *   index stored under `<prefix>__index__` to make `list()` and prefix `delete()` possible.
     *   The index is updated on `write()` and `append()` but may become stale if entries expire
     *   or are evicted by the server. Call `collectGarbage()` periodically to prune dead entries
     *   from the index.
     * - Unlike Redis, Memcached does not support TTL extension on existing keys. A `write()` call
     *   with `$defaultTtl > 0` will always set the complete TTL regardless of the remaining lifetime.
     * @package Wingman\Stasis\Adapters
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class MemcachedAdapter implements AdapterInterface, LockableInterface, StatsProviderInterface {
        /**
         * The default TTL in seconds applied by `write()` and `append()` at the Memcached layer.
         * A value of `0` means Memcached will keep the entry until it is evicted under memory pressure.
         * Eviction timing is then controlled entirely by `Cache::isFresh()` inside `Cacher`.
         * @var int
         */
        private int $defaultTtl;

        /**
         * The underlying Memcached instance.
         * @var Memcached
         */
        private Memcached $memcached;

        /**
         * The key prefix used to namespace all entries managed by this adapter.
         * @var string
         */
        private string $prefix;

        /**
         * Creates a new Memcached adapter.
         * @param Memcached $memcached A `Memcached` instance with at least one server already configured.
         * @param string $prefix A key prefix to namespace all entries and prevent collisions with other
         *                       applications sharing the same Memcached pool. Defaults to `"wingman_cache:"`.
         * @param int $defaultTtl Default TTL in seconds applied at the Memcached layer. Pass `0` to
         *                        disable server-side expiry and rely solely on `Cache::isFresh()`.
         * @throws StorageException If the `ext-memcached` extension is not loaded.
         */
        public function __construct (Memcached $memcached, string $prefix = "wingman_cache:", int $defaultTtl = 0) {
            if (!extension_loaded("memcached")) {
                throw new StorageException("The Memcached extension is required but not loaded.");
            }

            $this->memcached = $memcached;
            $this->prefix = $prefix;
            $this->defaultTtl = $defaultTtl;
        }

        /**
         * Returns the key under which the prefix index for a given directory path is stored.
         * The index is a pipe-delimited list of logical paths that share the directory as a prefix.
         * @param string $directoryPath The directory-style path prefix.
         * @return string The Memcached index key.
         */
        private function buildIndexKey (string $directoryPath) : string {
            return $this->prefix . rtrim($directoryPath, "/") . "/__index__";
        }

        /**
         * Returns the fully prefixed Memcached key for the given path.
         * @param string $path The logical cache path.
         * @return string The Memcached key with the adapter prefix applied.
         */
        private function buildKey (string $path) : string {
            return $this->prefix . $path;
        }

        /**
         * Removes a logical path from every ancestor prefix index.
         * @param string $path The logical cache path to deregister.
         */
        private function deregisterFromIndices (string $path) : void {
            foreach ($this->getAncestorPrefixes($path) as $ancestor) {
                $indexKey = $this->buildIndexKey($ancestor);
                $existing = $this->memcached->get($indexKey);

                if ($existing === false || $this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
                    continue;
                }

                $entries = array_filter(
                    explode("|", $existing),
                    fn (string $e) => $e !== "" && $e !== $path
                );

                $this->memcached->set($indexKey, implode("|", $entries), 0);
            }
        }

        /**
         * Returns all directory-style prefixes that are "ancestors" of the given path.
         * For example, `"a/b/c.cache"` yields `["a/b", "a", ""]`.
         * An empty string represents the adapter root and maps to the top-level index.
         * @param string $path The logical cache path.
         * @return string[] An array of ancestor directory paths, from deepest to shallowest.
         */
        private function getAncestorPrefixes (string $path) : array {
            $parts = explode("/", $path);
            array_pop($parts);
            $ancestors = [];

            while (!empty($parts)) {
                $ancestors[] = implode("/", $parts);
                array_pop($parts);
            }

            $ancestors[] = "";
            return $ancestors;
        }

        /**
         * Registers a logical path in every ancestor prefix index so that `list()` can locate it.
         * @param string $path The logical cache path to register.
         */
        private function registerInIndices (string $path) : void {
            foreach ($this->getAncestorPrefixes($path) as $ancestor) {
                $indexKey = $this->buildIndexKey($ancestor);
                $existing = $this->memcached->get($indexKey);
                $entries = ($existing !== false && $this->memcached->getResultCode() === Memcached::RES_SUCCESS)
                    ? array_filter(explode("|", $existing), fn (string $e) => $e !== "")
                    : [];

                if (!in_array($path, $entries, true)) {
                    $entries[] = $path;
                    $this->memcached->set($indexKey, implode("|", $entries), 0);
                }
            }
        }

        /**
         * Attempts to acquire an exclusive lock using `Memcached::add()`, which only sets the key if
         * it does not already exist — providing an atomic compare-and-set across the cluster.
         * @param string $key The logical cache key the lock protects.
         * @param int $ttl Maximum seconds before the lock auto-releases.
         * @return bool Whether the lock was acquired by this process.
         */
        public function acquireLock (string $key, int $ttl = 30) : bool {
            return $this->memcached->add($this->buildKey("__lock__:$key"), 1, $ttl);
        }

        /**
         * Appends content to an existing Memcached entry, creating it if it does not yet exist.
         * Registers the key in the prefix indices so that `list()` can discover it.
         * @param string $path The cache key.
         * @param string $contents The content to append.
         * @return bool Whether the operation succeeded.
         */
        public function append (string $path, string $contents) : bool {
            $key = $this->buildKey($path);
            $result = $this->memcached->append($key, $contents);

            if (!$result || $this->memcached->getResultCode() === Memcached::RES_NOTSTORED) {
                $result = $this->memcached->set($key, $contents, $this->defaultTtl);
            }

            if ($result) {
                $this->registerInIndices($path);
            }

            return $result;
        }

        /**
         * Deletes an exact Memcached key or, when no exact match exists, all keys whose logical path
         * begins with the given path (simulating directory deletion).
         * @param string $path The exact cache key or a directory-style path prefix.
         * @return bool Whether the deletion succeeded (always `true` for prefix deletion).
         */
        public function delete (string $path) : bool {
            $key = $this->buildKey($path);

            if ($this->memcached->get($key) !== false && $this->memcached->getResultCode() === Memcached::RES_SUCCESS) {
                $this->deregisterFromIndices($path);
                return $this->memcached->delete($key);
            }

            foreach ($this->list($path) as $childPath) {
                $this->deregisterFromIndices($childPath);
                $this->memcached->delete($this->buildKey($childPath));
            }

            $this->memcached->delete($this->buildIndexKey($path));
            return true;
        }

        /**
         * Checks whether an exact Memcached key exists.
         * @param string $path The cache key.
         * @return bool Whether the key exists.
         */
        public function exists (string $path) : bool {
            $this->memcached->get($this->buildKey($path));
            return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
        }

        /**
         * Returns storage-level statistics from Memcached using `getStats()`.
         * Aggregates figures across all servers in the cluster.
         * @return array The statistics array.
         */
        public function getStats () : array {
            $raw = $this->memcached->getStats();

            $totalItems = 0;
            $totalBytes = 0;
            $totalHits = 0;
            $totalMisses = 0;

            foreach ((array) $raw as $serverStats) {
                $totalItems += (int) ($serverStats['curr_items'] ?? 0);
                $totalBytes += (int) ($serverStats['bytes'] ?? 0);
                $totalHits += (int) ($serverStats['get_hits'] ?? 0);
                $totalMisses += (int) ($serverStats['get_misses'] ?? 0);
            }

            return [
                "total_keys" => $totalItems,
                "total_size" => round($totalBytes / 1024 / 1024, 2) . " MB",
                "hits" => $totalHits,
                "misses" => $totalMisses,
                "adapter" => "MemcachedAdapter",
                "status" => "Online",
                "server_count" => count((array) $raw)
            ];
        }

        /**
         * Returns all logical paths registered under the given directory-style prefix by consulting
         * the prefix index. Results may include entries that have been silently evicted by the server;
         * callers should verify existence via `exists()` before operating on the returned paths.
         * @param string $path The directory-style path prefix.
         * @return string[] An array of logical cache paths registered under the prefix.
         */
        public function list (string $path) : array {
            $indexKey = $this->buildIndexKey($path);
            $existing = $this->memcached->get($indexKey);

            if ($existing === false || $this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
                return [];
            }

            return array_values(array_filter(explode("|", $existing), fn (string $e) => $e !== ""));
        }

        /**
         * Reads a Memcached entry and returns its value as a string.
         * Returns an empty string when the key does not exist or has been evicted.
         * @param string $path The cache key.
         * @return string The cached content, or an empty string if the key is absent.
         */
        public function read (string $path) : string {
            $value = $this->memcached->get($this->buildKey($path));

            if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
                return "";
            }

            return (string) $value;
        }

        /**
         * Releases a previously acquired lock by deleting its Memcached key.
         * @param string $key The logical cache key whose lock should be released.
         * @return bool Whether the key was successfully deleted.
         */
        public function releaseLock (string $key) : bool {
            return $this->memcached->delete($this->buildKey("__lock__:$key"));
        }

        /**
         * Stores a string value under the given Memcached key and registers it in all ancestor
         * prefix indices so that `list()` can discover it later.
         * @param string $path The cache key.
         * @param string $contents The content to store.
         * @return bool Whether the write succeeded.
         */
        public function write (string $path, string $contents) : bool {
            $result = $this->memcached->set($this->buildKey($path), $contents, $this->defaultTtl);

            if ($result) {
                $this->registerInIndices($path);
            }

            return $result;
        }
    }
?>