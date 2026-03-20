<?php
    /**
     * Project Name:    Wingman Stasis - Invalid Shard Config Exception
     * Created by:      Angel Politis
     * Creation Date:   Mar 13 2026
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher.Exceptions namespace.
    namespace Wingman\Stasis\Exceptions;

    # Import the following classes to the current scope.
    use InvalidArgumentException;
    use Wingman\Stasis\Interfaces\Exception;

    /**
     * Thrown in strict mode when the configured shard length falls outside the
     * permitted range defined by `cacher.minShardLength` and `cacher.maxShardLength`.
     * In non-strict mode the value is silently clamped instead.
     * @package Wingman\Stasis\Exceptions
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class InvalidShardConfigException extends InvalidArgumentException implements Exception {}
?>