<?php
    /**
     * Project Name:    Wingman Stasis - Tag Manager
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis;

    # Import the following classes to the current scope.
    use Throwable;
    use Wingman\Stasis\Bridge\Cortex\Attributes\Configurable;
    use Wingman\Stasis\Bridge\Cortex\Configuration;

    /**
     * Manages tags for cache files.
     * @package Wingman\Stasis
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class TagManager {
        /**
         * The directory name used for storing tag index files.
         * @var string
         */
        #[Configurable("cacher.tagDirectory")]
        protected string $tagDirectory = "tags";

        /**
         * The file extension used for tag index files.
         * @var string
         */
        #[Configurable("cacher.indexExtension")]
        protected string $indexExtension = "idx";

        /**
         * Whether the tag manager should operate in maximum performance mode, which minimises file I/O at the cost of potentially stale tag indices.
         * @var bool
         */
        #[Configurable("cacher.maxPerformanceMode")]
        protected bool $maxPerformanceMode = false;

        /**
         * The cacher that uses a tag manager to manage tags for cache files.
         * @var Cacher
         */
        protected Cacher $cacher;

        /**
         * A buffer to track registered tags during the current execution to minimise file I/O.
         * The keys are unique fingerprints of cachePath-tag combinations, and the values are booleans (true).
         * @var array
         */
        protected array $tagBuffer = [];

        /**
         * The configuration object used by the tag manager to populate its properties.
         * @var Configuration
         */
        protected Configuration $config;

        /**
         * Creates a new tag manager.
         * @param Cacher $cacher The cacher that uses this tag manager. The tag manager will use the cacher's configuration to populate its properties.
         */
        public function __construct (Cacher $cacher) {
            $this->cacher = $cacher;
            $this->config = $cacher->getConfig();
            Configuration::hydrate($this, $this->config);
        }


        /**
         * Clears the in-process tag buffer, forcing the next `registerTags()` call to re-check
         * the index files rather than relying on buffered state. Must be called after `Cacher::clear()`
         * to prevent stale fingerprints from silently suppressing tag re-registration.
         * @return static The tag manager.
         */
        public function clearBuffer () : static {
            $this->tagBuffer = [];
            return $this;
        }

        /**
         * Gets the file extension used for tag index files.
         * @return string The file extension used for tag index files.
         */
        public function getIndexExtension () : string {
            return $this->indexExtension;
        }

        /**
         * Gets the name of the tag directory.
         * @return string The name of the tag directory.
         */
        public function getTagDirectory () : string {
            return PathUtils::join($this->tagDirectory);
        }

        /**
         * Rebuilds the tag indices by scanning all cache files and re-registering their tags.
         * This is useful for recovering from a corrupted tags directory or ensuring that the tag
         * indices are accurate after manual changes to cache files.
         * @return int The number of cache files that were processed and had their tags registered.
         */
        public function rebuildTagIndices () : int {
            $adapter = $this->cacher->getAdapter();
            $tagDir = $this->cacher->getAbsolutePath($this->getTagDirectory());

            # 1. Clear existing indices to start fresh
            if ($adapter->exists($tagDir)) {
                $adapter->delete($tagDir);
            }
            
            # Reset the buffer for this process
            $this->tagBuffer = [];
            $processedCount = 0;

            # 2. Get all cache files (excluding the tags directory itself)
            $cacheFiles = $this->cacher->getCacheFiles();

            foreach ($cacheFiles as $path) {
                try {
                    $raw = $adapter->read($path);
                    $cache = unserialize($raw, ["allowed_classes" => [Cache::class]]);

                    if ($cache instanceof Cache && !empty($cache->getTags())) {
                        # 3. Register the tags found in the cache object
                        $this->registerTags($path, $cache->getTags());
                        $processedCount++;
                    }
                }
                catch (Throwable $e) {
                    continue;
                }
            }

            return $processedCount;
        }

        /**
         * Registers the given tags for the specified cache file path.
         * @param string $cachePath The path of the cache file to associate with the tags.
         * @param array $tags The tags to register for the cache file.
         * @return static The tag manager.
         */
        public function registerTags (string $cachePath, array $tags) : static {
            $adapter = $this->cacher->getAdapter();

            # Normalise path once outside the loops.
            $relativeCachePath = str_replace($this->cacher->getAbsolutePath(), "", $cachePath);
            $relativeCachePath = ltrim($relativeCachePath, DIRECTORY_SEPARATOR);
            $entry = $relativeCachePath . PHP_EOL;

            # Branch 1: Max Performance (Blind Append)
            if ($this->maxPerformanceMode) {
                foreach ($tags as $tag) {
                    $fingerprint = md5($cachePath . $tag);
                    if (isset($this->tagBuffer[$fingerprint])) continue;

                    $tagHash = hash($this->cacher->getHashingAlgorithm(), $tag);
                    $tagFile = $this->cacher->getAbsolutePath(PathUtils::join($this->tagDirectory, "$tagHash.{$this->indexExtension}"));

                    if ($adapter->append($tagFile, $entry)) {
                        $this->tagBuffer[$fingerprint] = true;
                    }
                }
                return $this;
            }
            
            # Branch 2: Standard Mode (Read-Check-Append)
            foreach ($tags as $tag) {
                $fingerprint = md5($cachePath . $tag);
                if (isset($this->tagBuffer[$fingerprint])) continue;

                $tagHash = hash($this->cacher->getHashingAlgorithm(), $tag);
                $tagFile = $this->cacher->getAbsolutePath(PathUtils::join($this->tagDirectory, "$tagHash.{$this->indexExtension}"));

                $existing = $adapter->exists($tagFile) ? $adapter->read($tagFile) : "";
                $alreadyPresent = str_contains($existing, $entry);

                if ($alreadyPresent || $adapter->append($tagFile, $entry)) {
                    $this->tagBuffer[$fingerprint] = true;
                }
            }

            return $this;
        }

        /**
         * Synchronises tag indices by removing specified deleted paths and verifying the existence of others.
         * This helps maintain accurate tag indices and can be used after cache files have been deleted to clean up the corresponding tag entries.
         * @param array $knownDeleted An array of cache file paths that are known to have been deleted, to optimise the synchronisation process by skipping existence checks for these paths.
         * @return int The number of tag indices that were synchronised.
         */
        public function synchroniseIndices (array $knownDeleted = []) : int {
            $adapter = $this->cacher->getAdapter();
            $tagDir = $this->cacher->getAbsolutePath($this->getTagDirectory());

            if (!$adapter->exists($tagDir)) return 0;

            $indexFiles = $adapter->list($tagDir);
            $prunedCount = 0;

            foreach ($indexFiles as $indexFile) {
                if (!str_ends_with($indexFile, $this->indexExtension)) continue;

                $raw = $adapter->read($indexFile);
                $paths = explode(PHP_EOL, trim($raw));
                $remaining = [];
                $changed = false;

                foreach ($paths as $path) {
                    $path = trim($path);
                    if (empty($path)) continue;

                    # 1. Quick check: Was it just deleted by the Cacher?
                    if (in_array($path, $knownDeleted)) {
                        $changed = true;
                        continue;
                    }

                    # 2. Deep check: Does it exist on disk?
                    if ($adapter->exists($this->cacher->getAbsolutePath($path))) {
                        $remaining[] = $path;
                    }
                    else $changed = true;
                }

                if ($changed) {
                    $prunedCount++;
                    if (empty($remaining)) {
                        $adapter->delete($indexFile);
                    }
                    else {
                        $adapter->write($indexFile, implode(PHP_EOL, $remaining) . PHP_EOL);
                    }
                }
            }

            return $prunedCount;
        }
    }
?>