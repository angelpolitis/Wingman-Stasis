<?php
    /**
     * Project Name:    Wingman Stasis - Lockable Interface
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
     * Optional capability interface implemented by adapters that can acquire and release exclusive
     * cache-level locks. When a `Cacher` adapter implements this interface, methods such as
     * `remember()` use it to prevent cache stampedes.
     *
     * The locking protocol is a standard try-lock / double-check / release pattern:
     *
     * 1. On a cache miss, `acquireLock()` is called with a derived lock key.
     * 2. The lock winner re-reads the cache (double-check) in case another process populated the
     *    entry while the current process was waiting to acquire the lock.
     * 3. If still a miss, the winner computes the value, stores it, then calls `releaseLock()`.
     * 4. Losers who cannot acquire the lock spin with a short back-off and retry reading until the
     *    TTL on the lock key expires, then fall through and compute the value independently.
     *
     * Lock keys are kept separate from regular cache keys by the adapter's implementation; callers
     * need not (and must not) namespace them manually.
     *
     * Single-server guarantees:
     * - `LocalAdapter` uses an exclusive `flock()` on a sidecar `.lock` file.
     * - `ApcuAdapter` uses `apcu_add()` which is atomic at the SHM layer.
     *
     * Distributed guarantees:
     * - `RedisAdapter` uses the canonical `SET key value NX PX ttl` pattern, which is atomic.
     * - `MemcachedAdapter` uses `Memcached::add()`, which sets the key only if absent.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface LockableInterface {
        /**
         * Attempts to acquire an exclusive lock for the given cache key.
         * @param string $key The logical cache key the lock protects.
         * @param int $ttl Maximum number of seconds the lock may be held before it is automatically
         *                 released by the storage layer, preventing infinite blocking if the lock
         *                 holder crashes. Must be greater than `0`.
         * @return bool Whether the lock was successfully acquired by the current process.
         */
        public function acquireLock (string $key, int $ttl = 30) : bool;

        /**
         * Releases a previously acquired lock for the given cache key.
         * Releasing a lock that was never acquired or that has already expired is a no-op.
         * @param string $key The logical cache key whose lock should be released.
         * @return bool Whether the lock was successfully released.
         */
        public function releaseLock (string $key) : bool;
    }
?>