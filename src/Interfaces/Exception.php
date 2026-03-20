<?php
    /**
     * Project Name:    Wingman Stasis - Cacher Exception
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

    /**
     * Marker interface implemented by every exception thrown by the Wingman Stasis
     * package. Catching `Exception` will intercept all package-specific exceptions
     * regardless of their concrete base class.
     * @package Wingman\Stasis\Interfaces
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    interface Exception {}
?>