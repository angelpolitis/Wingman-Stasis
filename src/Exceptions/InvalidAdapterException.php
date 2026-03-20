<?php
    /**
     * Project Name:    Wingman Stasis - Invalid Adapter Exception
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
     * Thrown when an adapter or proxy supplied to the caching system does not satisfy
     * the `AdapterInterface` contract. This covers three distinct scenarios:
     * - An adapter object is passed directly but does not implement `AdapterInterface`.
     * - A proxy map is supplied but one or more required interface methods are absent
     *   from the resulting proxy.
     * - An adapter class name configured in a store definition resolves to an object
     *   that does not implement `AdapterInterface`.
     * @package Wingman\Stasis\Exceptions
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class InvalidAdapterException extends InvalidArgumentException implements Exception {}
?>