<?php
    /**
     * Project Name:    Wingman Stasis - Cortex Bridge - Configurable
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Stasis.Bridge.Cortex.Attributes namespace.
    namespace Wingman\Stasis\Bridge\Cortex\Attributes;

    # Guard against double-inclusion (e.g. via symlinked paths resolving to different strings
    # under require_once). If the alias or stub is already in place there is nothing to do.
    if (class_exists(__NAMESPACE__ . '\\Configurable', false)) return;

    # Import the following classes to the current scope.
    use Attribute;

    # If Cortex is installed, alias its Configurable attribute into this namespace.
    if (class_exists(\Wingman\Cortex\Attributes\Configurable::class)) {
        class_alias(\Wingman\Cortex\Attributes\Configurable::class, __NAMESPACE__ . '\\Configurable');
        return;
    }

    /**
     * A no-op stub attribute used when Cortex is not available.
     * Properties annotated with this attribute are recognised by the `Configuration` stub's
     * `hydrate()` method. When Cortex IS installed, this file aliases the real
     * `Wingman\Cortex\Attributes\Configurable` into this namespace so that Cortex's own
     * `ObjectHydrator` — which uses `is_a()` with `allow_string = true` — correctly recognises
     * attributes declared with the bridge class name.
     * @package Wingman\Stasis\Bridge\Cortex\Attributes
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    #[Attribute]
    class Configurable {
        /**
         * The configuration key.
         * @var string
         */
        private string $key;

        /**
         * Creates a new configurable attribute.
         * @param string $key The configuration key.
         */
        public function __construct (string $key, ...$args) {
            $this->key = $key;
        }

        /**
         * Gets the configuration key.
         * @return string The configuration key.
         */
        public function getKey () : string {
            return $this->key;
        }
    }
?>