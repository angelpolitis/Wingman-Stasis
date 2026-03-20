<?php
    /**
     * Project Name:    Wingman Stasis - Non-Numeric Value Exception
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
     * Thrown when an increment or decrement operation is attempted on a cache key whose
     * stored value is not numeric. Counter operations require the existing value to be
     * an integer or a string that is numeric in the PHP sense.
     * @package Wingman\Stasis\Exceptions
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class NonNumericValueException extends InvalidArgumentException implements Exception {}
?>