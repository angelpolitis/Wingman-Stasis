<?php
    /**
     * Project Name:    Wingman Stasis - Provider
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
    use Wingman\Cortex\Configuration;
    use Wingman\Stasis\Cacher;
    use Wingman\Stasis\Exceptions\StorageException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Synapse\Provider as BaseProvider;

    /**
     * Registers the Cacher and its dependencies with the Synapse DI container.
     *
     * The Cacher is bound as a singleton under `Cacher::class` and aliased to the
     * string key `"cacher"` for convenient resolution. When an `AdapterInterface`
     * binding is present in the container the resolved adapter is forwarded to `Cacher`;
     * otherwise `null` is passed so that `Cacher` constructs a `LocalAdapter` using the
     * permission value sourced from the Cortex configuration rather than `0755`.
     * @package Wingman\Stasis\Bridge\Synapse
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class Provider extends BaseProvider {
        /**
         * Boots the cacher service after all providers have been registered.
         * Ensures that the configured cache root directory exists and is writable,
         * throwing a `RuntimeException` if the directory cannot be created.
         * @throws StorageException If the cache root directory cannot be created.
         */
        public function boot () : void {
            $cacher = $this->container->make(Cacher::class);
            $root = $cacher->getRootDirectory();

            if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
                throw new StorageException("Cache root directory '$root' could not be created.");
            }
        }

        /**
         * Registers the Cacher singleton and its `"cacher"` alias with the container.
         * The adapter is resolved from the container if an `AdapterInterface` binding exists;
         * otherwise `null` is passed so that `Cacher` constructs a `LocalAdapter` with the
         * permission value it reads from the Cortex configuration, rather than the hardcoded `0755`
         * that would result from constructing `LocalAdapter` here without that context.
         */
        public function register () : void {
            $this->container->bindSingleton(Cacher::class, function ($container) {
                $adapter = $container->has(AdapterInterface::class)
                    ? $container->make(AdapterInterface::class)
                    : null;

                $config = $container->has(Configuration::class)
                    ? $container->make(Configuration::class)
                    : null;

                return new Cacher(
                    adapter: $adapter,
                    config: $config
                );
            });

            $this->container->alias(Cacher::class, "cacher");
        }
    }
?>