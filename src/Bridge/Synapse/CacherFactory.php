<?php
    /**
     * Project Name:    Wingman Stasis - Cacher Factory
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher.Bridge.Synapse namespace.
    namespace Wingman\Stasis\Bridge\Synapse;

    # Import the following classes to the current scope.
    use Wingman\Stasis\Cacher;
    use Wingman\Stasis\Adapters\LocalAdapter;
    use Wingman\Stasis\Exceptions\InvalidAdapterException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Bridge\Cortex\Configuration;
    use Wingman\Synapse\Container;

    /**
     * Resolves and manages named Cacher instances (stores) on behalf of the
     * Synapse container. Each store is created once and reused for the duration of
     * the request lifecycle, making this class an internal singleton pool.
     *
     * Store configuration is read from the configuration under the key
     * `cacher.stores.<name>` and may contain the following options:
     * - `root`       (string)  Absolute or relative path to the cache directory.
     * - `permission` (int)     Octal permission used when creating directories and files.
     * - `adapter`    (string)  Fully-qualified class name of the adapter to use.
     *
     * When no adapter class is specified for a store, a `LocalAdapter` is used as
     * the default. When one is specified, it is resolved via the container and
     * validated against `AdapterInterface`.
     * @package Wingman\Stasis\Bridge\Synapse
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class CacherFactory {
        /**
         * The Synapse DI container used to resolve adapter dependencies.
         * @var Container
         */
        protected Container $container;

        /**
         * The configuration used to hydrate store settings.
         * @var Configuration
         */
        protected Configuration $configuration;

        /**
         * The pool of resolved Cacher instances, keyed by store name.
         * @var Cacher[]
         */
        protected array $instances = [];

        /**
         * Creates a new cacher factory.
         * @param Container $container The Synapse DI container.
         * @param Configuration $configuration The configuration.
         */
        public function __construct (Container $container, Configuration $configuration) {
            $this->container = $container;
            $this->configuration = $configuration;
        }

        /**
         * Creates a new Cacher instance for the given store name, reading its
         * settings from the configuration and resolving its adapter via the container.
         * @param string $name The store name.
         * @return Cacher The fully initialised cacher.
         * @throws InvalidAdapterException If a configured adapter class does not implement `AdapterInterface`.
         */
        protected function createInstance (string $name) : Cacher {
            $settings = $this->configuration->get("cacher.stores.$name", []);
            $adapterClass = $settings["adapter"] ?? null;

            if ($adapterClass !== null) {
                $adapter = $this->container->make($adapterClass);

                if (!$adapter instanceof AdapterInterface) {
                    throw new InvalidAdapterException("Adapter class '$adapterClass' must implement AdapterInterface.");
                }
            } else {
                $adapter = new LocalAdapter();
            }

            return new Cacher(
                root: $settings["root"] ?? null,
                permission: $settings["permission"] ?? null,
                adapter: $adapter,
                config: $this->configuration
            );
        }

        /**
         * Resolves a named cache store, creating it on first access and returning
         * the same instance on subsequent calls.
         * @param string|null $name The store name; if `null`, the value of the
         *                          `cacher.default_store` configuration variable is used,
         *                          falling back to `"default"`.
         * @return Cacher The resolved cacher.
         */
        public function store (?string $name = null) : Cacher {
            $name ??= $this->configuration->get("cacher.default_store", "default");

            if (!isset($this->instances[$name])) {
                $this->instances[$name] = $this->createInstance($name);
            }

            return $this->instances[$name];
        }
    }
?>