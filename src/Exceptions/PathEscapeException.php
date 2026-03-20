<?php
    /**
     * Project Name:    Wingman Stasis - Path Escape Exception
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
    use RuntimeException;
    use Wingman\Stasis\Interfaces\Exception;

    /**
     * Thrown when a resolved path escapes the configured cache root directory.
     * This acts as a path-traversal guard: if a caller provides a relative path
     * such as `../../etc/passwd` and strict mode is enabled, this exception is
     * raised rather than allowing the operation to proceed outside the root.
     * @package Wingman\Stasis\Exceptions
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class PathEscapeException extends RuntimeException implements Exception {}
?>