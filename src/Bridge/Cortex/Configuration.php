<?php
    /**
     * Project Name:    Wingman Stasis - Cortex Bridge - Configuration
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Stasis.Bridge.Cortex namespace.
    namespace Wingman\Stasis\Bridge\Cortex;

    # Guard against double-inclusion (e.g. via symlinked paths resolving to different strings
    # under require_once). If the alias or stub is already in place there is nothing to do.
    if (class_exists(__NAMESPACE__ . '\\Configuration', false)) return;

    # If Cortex is installed, alias its Configuration class into this namespace.
    if (class_exists(\Wingman\Cortex\Configuration::class)) {
        class_alias(\Wingman\Cortex\Configuration::class, __NAMESPACE__ . '\\Configuration');
        return;
    }

    # Import the following classes to the current scope.
    use ReflectionNamedType;
    use ReflectionObject;

    /**
     * A no-op stub used when Cortex is not available.
     * Mirrors the subset of `Wingman\Cortex\Configuration`'s API that Stasis relies on, so
     * all call sites remain valid without Cortex installed. Properties annotated with
     * `#[Configurable]` stay at their declared defaults; `get()` always returns the
     * provided fallback value.
     *
     * When Cortex IS installed, this file simply aliases the real `Wingman\Cortex\Configuration`
     * into this namespace and returns immediately — the stub class body is never defined.
     * Cortex's real hydrator uses `is_a()` with `allow_string = true` when checking attribute
     * types, so PHP class aliasing is fully transparent to it.
     * @package Wingman\Stasis\Bridge\Cortex
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class Configuration {
        /**
         * Returns `null`; Cortex is unavailable so no named configuration can be found.
         * @param string|null $name Ignored.
         * @return static|null Always `null`.
         */
        public static function find (?string $name = null) : ?static {
            return null;
        }

        /**
         * Hydrates `#[Configurable]`-annotated properties of `$target` from `$source`.
         * When `$source` is a stub instance it is returned immediately — no real configuration
         * data is available. When `$source` is a plain array the properties are populated from
         * it, so Stasis can be driven by raw arrays without Cortex installed.
         * @param object $target The object whose properties should be hydrated.
         * @param array|self $source A flat dot-notation key-value array, or a stub instance.
         * @param array $map Ignored; present for API compatibility.
         * @param bool $strict Ignored; present for API compatibility.
         * @return self The stub instance.
         */
        public static function hydrate (object $target, array|self $source = [], array $map = [], bool $strict = false) : self {
            if (is_array($source) && !empty($source)) {
                $reflection = new ReflectionObject($target);

                foreach ($reflection->getProperties() as $property) {
                    foreach ($property->getAttributes() as $attribute) {
                        if ($attribute->getName() !== \Wingman\Stasis\Bridge\Cortex\Attributes\Configurable::class) continue;

                        $configurable = $attribute->newInstance();
                        $key = method_exists($configurable, "getKey") ? $configurable->getKey() : $property->getName();

                        if (!array_key_exists($key, $source)) continue;

                        $value = $source[$key];
                        $type = $property->getType();

                        if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                            $value = match ($type->getName()) {
                                'bool' => (bool) $value,
                                'int' => (int) $value,
                                'float' => (float) $value,
                                'string' => (string) $value,
                                default => $value,
                            };
                        }

                        $property->setValue($target, $value);
                    }
                }
            }

            return $source instanceof self ? $source : new static();
        }

        /**
         * Returns the default value because Cortex is unavailable and no data is held.
         * @param string $key Ignored.
         * @param mixed $default The fallback value.
         * @return mixed The default value.
         */
        public function get (string $key, mixed $default = null) : mixed {
            return $default;
        }
    }
?>