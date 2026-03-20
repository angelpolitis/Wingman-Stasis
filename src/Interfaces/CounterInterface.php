<?php
    /**
     * Project Name:    Wingman Stasis - Counter Interface
     * Created by:      Angel Politis
     * Creation Date:   Mar 13 2026
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher.Interfaces namespace.
    namespace Wingman\Stasis\Interfaces;

    # Import the following classes to the current scope.
    use Wingman\Stasis\Exceptions\NonNumericValueException;

    /**
     * Optional capability interface implemented by adapters that can perform atomic integer
     * counter operations natively, without a separate read-modify-write cycle.
     *
     * When a `Cacher` adapter implements this interface, `increment()` and `decrement()` will
     * delegate to `adjustCounter()` instead of performing a best-effort read-modify-write over
     * the generic `read()`/`write()` API. This provides true cross-process atomicity for
     * workloads such as rate-limiting.
     *
     * Counter keys and regular serialised cache keys are mutually exclusive. A key written
     * by `Cacher::set()` cannot be atomically incremented via this interface and vice versa.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface CounterInterface {
        /**
         * Atomically adjusts a raw integer counter stored at the given path/key.
         *
         * - If the key does not exist it is initialised to `0` and `$delta` is applied,
         *   yielding `$delta` as the return value.
         * - If the key exists but does not hold a numeric value, `NonNumericValueException`
         *   is thrown.
         * - `$ttl === 0` means "no expiry at the storage layer".
         * @param string $path  The fully resolved storage path or key.
         * @param int    $delta The amount to add (positive) or subtract (negative).
         * @param int    $ttl   TTL in seconds; `0` means no expiry.
         * @return int The new counter value after the adjustment.
         * @throws NonNumericValueException If the stored value is not numeric.
         */
        public function adjustCounter (string $path, int $delta, int $ttl) : int;
    }
?>