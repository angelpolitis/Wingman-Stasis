<?php
    /**
     * Project Name:    Wingman Stasis - Redis Adapter
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
    use Redis;
    use RedisException;
    use Wingman\Stasis\Exceptions\NonNumericValueException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Interfaces\CounterInterface;
    use Wingman\Stasis\Interfaces\LockableInterface;
    use Wingman\Stasis\Interfaces\StatsProviderInterface;

    /**
     * Implements the AdapterInterface using a Redis server as the storage backend.
     * Requires the PHP `ext-redis` extension and a pre-connected `Redis` instance.
     *
     * All "paths" are stored as Redis string keys prefixed with the value passed to the
     * constructor. The concept of "directories" is emulated by scanning for keys that share
     * the given path as a common prefix via Redis `SCAN`.
     *
     * This adapter is well suited for:
     * - Multi-server (distributed) deployments where cache coherence is required.
     * - High-throughput workloads that need sub-millisecond read/write latency.
     * - Scenarios where cache persistence across process restarts is desirable
     *   (when configured with an appropriate Redis persistence strategy).
     * @package Wingman\Stasis\Adapters
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class RedisAdapter implements AdapterInterface, CounterInterface, LockableInterface, StatsProviderInterface {
        /**
         * The default TTL in seconds applied by `write()` and `append()`.
         * A value of `0` means no TTL is set at the Redis layer; eviction is then controlled
         * entirely by the `Cache` object's own expiry logic in `Cacher`.
         * @var int
         */
        private int $defaultTtl;

        /**
         * The key prefix used to namespace all entries managed by this adapter.
         * @var string
         */
        private string $prefix;

        /**
         * The underlying Redis connection instance.
         * @var Redis
         */
        private Redis $redis;

        /**
         * Creates a new Redis adapter.
         * @param Redis $redis A connected and authenticated `Redis` instance.
         * @param string $prefix A key prefix to namespace all entries and prevent collisions with other
         *                       applications sharing the same Redis server. Defaults to `"wingman_cache:"`.
         * @param int $defaultTtl Default TTL in seconds applied at the Redis layer so that entries are
         *                        automatically evicted even if `Cacher::collectGarbage()` is never called.
         *                        Pass `0` to disable Redis-level TTLs and rely solely on `Cache::isFresh()`.
         */
        public function __construct (Redis $redis, string $prefix = "wingman_cache:", int $defaultTtl = 0) {
            $this->redis = $redis;
            $this->prefix = $prefix;
            $this->defaultTtl = $defaultTtl;
        }

        /**
         * Returns the fully prefixed Redis key for the given path.
         * @param string $path The logical cache path.
         * @return string The Redis key with the adapter prefix applied.
         */
        private function buildKey (string $path) : string {
            return $this->prefix . $path;
        }

        /**
         * Scans for all Redis keys that match the given glob pattern using cursor-based iteration to
         * avoid blocking the server with a large `KEYS` call.
         * @param string $pattern A Redis glob pattern (e.g. `"prefix*"`).
         * @return string[] An array of matching full Redis keys (including the adapter prefix).
         */
        private function scanByPattern (string $pattern) : array {
            $cursor = null;
            $keys = [];

            do {
                $batch = $this->redis->scan($cursor, $pattern, 100);

                if ($batch !== false) {
                    foreach ($batch as $key) {
                        $keys[] = $key;
                    }
                }
            } while ($cursor > 0);

            return $keys;
        }

        /**
         * Attempts to acquire an exclusive lock using the canonical `SET key value NX PX ttl` Redis
         * command, which is a single atomic operation safe across processes and servers.
         * @param string $key The logical cache key the lock protects.
         * @param int $ttl Maximum seconds before the lock auto-releases.
         * @return bool Whether the lock was acquired by this process.
         */
        public function acquireLock (string $key, int $ttl = 30) : bool {
            $lockKey = $this->buildKey("__lock__:$key");
            return (bool) $this->redis->set($lockKey, 1, ['nx', 'ex' => $ttl]);
        }

        /**
         * Atomically adjusts a raw integer counter stored at a Redis key.
         *
         * Delegates to `INCRBY` / `DECRBY` which are single-command atomic operations in Redis,
         * providing genuine cross-process and cross-server safety. Redis initialises a missing
         * key to `0` before applying the delta automatically. When the key holds a non-numeric
         * string Redis returns an error, which is normalised into a `NonNumericValueException`.
         * @param string $path  The logical cache path; the adapter prefix is applied internally.
         * @param int    $delta Amount to add (positive) or subtract (negative).
         * @param int    $ttl   TTL in seconds; `0` means no expiry at the Redis layer.
         * @return int The new counter value.
         * @throws NonNumericValueException If the stored value is not numeric.
         */
        public function adjustCounter (string $path, int $delta, int $ttl) : int {
            $key = $this->buildKey($path);

            try {
                $new = $delta >= 0
                    ? $this->redis->incrBy($key, $delta)
                    : $this->redis->decrBy($key, abs($delta));
            }
            catch (RedisException $e) {
                throw new NonNumericValueException("Cannot perform a counter operation on a non-numeric cache value at \"$path\".", 0, $e);
            }

            if ($new === false) {
                throw new NonNumericValueException("Cannot perform a counter operation on a non-numeric cache value at \"$path\".");
            }

            if ($ttl > 0 && $this->redis->ttl($key) === -1) {
                $this->redis->expire($key, $ttl);
            }

            return (int) $new;
        }

        /**
         * Appends a string to an existing Redis entry, creating the entry if it does not yet exist.
         * When a `$defaultTtl` was provided at construction time and the target key has no TTL set,
         * a TTL is applied after the append to bound the entry's lifetime.
         * @param string $path The cache key.
         * @param string $contents The content to append.
         * @return bool Whether the operation succeeded.
         */
        public function append (string $path, string $contents) : bool {
            $key = $this->buildKey($path);
            $this->redis->append($key, $contents);

            if ($this->defaultTtl > 0 && $this->redis->ttl($key) === -1) {
                $this->redis->expire($key, $this->defaultTtl);
            }

            return true;
        }

        /**
         * Deletes a Redis key or, when no exact key matches, all keys that share the path as a common
         * prefix (simulating directory deletion via `SCAN`).
         * @param string $path The exact cache key or a directory-style path prefix.
         * @return bool Whether at least the scan completed without error (`true` even when no keys matched).
         */
        public function delete (string $path) : bool {
            $key = $this->buildKey($path);

            if ($this->redis->exists($key)) {
                return (bool) $this->redis->del($key);
            }

            $matching = $this->scanByPattern($key . "*");

            foreach ($matching as $match) {
                $this->redis->del($match);
            }

            return true;
        }

        /**
         * Checks whether a Redis key exists.
         * @param string $path The cache key.
         * @return bool Whether the key exists.
         */
        public function exists (string $path) : bool {
            return (bool) $this->redis->exists($this->buildKey($path));
        }

        /**
         * Returns storage-level statistics from Redis using the `INFO` command.
         * @return array The statistics array.
         */
        public function getStats () : array {
            try {
                $info = $this->redis->info();
                $usedMemory = (int) ($info['used_memory'] ?? 0);

                return [
                    "total_keys" => (int) ($info['keyspace'] ?? array_sum(
                        array_map(
                            fn ($dbInfo) => (int) (explode(',', explode('=', $dbInfo)[1] ?? '0')[0]),
                            (array) ($info['db0'] ?? [])
                        )
                    )),
                    "total_size" => round($usedMemory / 1024 / 1024, 2) . " MB",
                    "hits" => (int) ($info['keyspace_hits'] ?? 0),
                    "misses" => (int) ($info['keyspace_misses'] ?? 0),
                    "adapter" => "RedisAdapter",
                    "status" => "Online",
                    "version" => $info['redis_version'] ?? "unknown",
                    "connected_clients" => (int) ($info['connected_clients'] ?? 0),
                    "uptime_seconds" => (int) ($info['uptime_in_seconds'] ?? 0)
                ];
            }
            catch (RedisException) {
                return [
                    "total_keys" => 0,
                    "total_size" => "N/A",
                    "hits" => 0,
                    "misses" => 0,
                    "adapter" => "RedisAdapter",
                    "status" => "Offline (connection error)"
                ];
            }
        }

        /**
         * Returns all logical paths (without the adapter prefix) whose Redis keys begin with the
         * given path prefix.
         * @param string $path The path prefix to search under.
         * @return string[] An array of matching logical cache paths.
         */
        public function list (string $path) : array {
            $prefixLen = strlen($this->prefix);
            $matching = $this->scanByPattern($this->buildKey($path) . "*");

            return array_map(fn (string $key) => substr($key, $prefixLen), $matching);
        }

        /**
         * Reads a Redis entry and returns its value as a string.
         * Returns an empty string when the key does not exist.
         * @param string $path The cache key.
         * @return string The cached content, or an empty string if the key is absent.
         */
        public function read (string $path) : string {
            $value = $this->redis->get($this->buildKey($path));
            return $value !== false ? (string) $value : "";
        }

        /**
         * Releases a previously acquired lock by deleting its Redis key.
         * @param string $key The logical cache key whose lock should be released.
         * @return bool Whether the key was removed.
         */
        public function releaseLock (string $key) : bool {
            return (bool) $this->redis->del($this->buildKey("__lock__:$key"));
        }

        /**
         * Stores a string value under the given Redis key.
         * When a `$defaultTtl` was provided at construction time, the key is stored via `SETEX`
         * with that TTL; otherwise a plain `SET` is used with no expiry at the Redis layer.
         * @param string $path The cache key.
         * @param string $contents The content to store.
         * @return bool Whether the write succeeded.
         */
        public function write (string $path, string $contents) : bool {
            $key = $this->buildKey($path);

            if ($this->defaultTtl > 0) {
                return (bool) $this->redis->setex($key, $this->defaultTtl, $contents);
            }

            return (bool) $this->redis->set($key, $contents);
        }
    }
?>