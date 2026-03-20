<?php
    /**
     * Project Name:    Wingman Stasis - Cacher
     * Created by:      Angel Politis
     * Creation Date:   Nov 11 2025
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2025-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis;

    # Import the following classes to the current scope.
    use DateInterval;
    use DateTimeImmutable;
    use FilesystemIterator;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use ReflectionClass;
    use stdClass;
    use Throwable;
    use Wingman\Stasis\Adapters\LocalAdapter;
    use Wingman\Stasis\Bridge\Cortex\Attributes\Configurable;
    use Wingman\Stasis\Bridge\Cortex\Configuration;
    use Wingman\Stasis\Exceptions\InvalidAdapterException;
    use Wingman\Stasis\Exceptions\InvalidShardConfigException;
    use Wingman\Stasis\Exceptions\NonNumericValueException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Interfaces\CounterInterface;
    use Wingman\Stasis\Interfaces\LockableInterface;
    use Wingman\Stasis\Interfaces\PrunableInterface;
    use Wingman\Stasis\Interfaces\StatsProviderInterface;

    # Define constants for the root and default cache directories.
    if (!defined("Wingman\\Stasis\\ROOT_DIR")) define("Wingman\\Stasis\\ROOT_DIR", dirname(__DIR__));
    if (!defined("Wingman\\Stasis\\CACHE_DIR")) define("Wingman\\Stasis\\CACHE_DIR", PathUtils::join("temp", "cache"));
    
    /**
     * Represents a caching system that can store and retrieve data efficiently using a file-based approach with sharding and hashing capabilities. 
     * It provides methods for managing cache items, including setting, getting, deleting, and clearing cache entries, while ensuring that the cache
     * directory structure is maintained and that cache items are properly validated for freshness.
     * @package Wingman\Stasis
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class Cacher {
        /**
         * The root directory of a cacher.
         * - When stored it will be relative to the package root for portability.
         * - When used it will be resolved to an absolute path for reliability.
         * @var string
         */
        #[Configurable("cacher.root")]
        protected string $root = CACHE_DIR;

        /**
         * The permission used by a cacher to create files.
         * @var int
         */
        #[Configurable("cacher.permission")]
        protected int $permission = 0755;

        /**
         * The hashing algorithm used by a cacher to create files.
         * @var string
         */
        #[Configurable("cacher.hashingAlgorithm")]
        protected string $hashingAlgorithm = "sha256";

        /**
         * The time-to-live in seconds of a cache file created by a cacher.
         * @var int
         */
        #[Configurable("cacher.ttl")]
        protected int $ttl = 86400;

        /**
         * Whether the cacher will operate in strict mode, which enforces stricter validation and error handling.
         * @var bool
         */
        #[Configurable("cacher.strict")]
        protected bool $strict = false;

        /**
         * The depth of sharding for cache files, which determines how many subdirectories will be created based on the hash of the cache key.
         * @var int
         */
        #[Configurable("cacher.shardDepth")]
        protected int $shardDepth = 2;

        /**
         * The length of each shard in the sharding process, which determines how many characters of the hash will be used for each subdirectory level.
         * @var int
         */
        #[Configurable("cacher.shardLength")]
        protected int $shardLength = 2;

        /**
         * The file extension used for cache files.
         * @var string
         */
        #[Configurable("cacher.cacheExtension")]
        protected string $cacheExtension = "cache";

        /**
         * The minimum allowed shard length for sharding cache files.
         * @var int
         */
        #[Configurable("cacher.minShardLength")]
        protected int $minShardLength = 1;

        /**
         * The maximum allowed shard length for sharding cache files.
         * @var int
         */
        #[Configurable("cacher.maxShardLength")]
        protected int $maxShardLength = 8;

        /**
         * Whether sharding is enabled for the cacher, which determines if cache files will be distributed into subdirectories based on their hash.
         * @var bool
         */
        #[Configurable("cacher.shardingEnabled")]
        protected bool $shardingEnabled = true;

        /**
         * Whether the cacher will log its operations for debugging or monitoring purposes.
         * @var bool
         */
        #[Configurable("cacher.loggingEnabled")]
        protected bool $loggingEnabled = false;

        /**
         * The filename used for the registry that tracks cache items and their expiration times.
         * @var string
         */
        #[Configurable("cacher.registryFile")]
        protected string $registryFile = "registry.idx";

        /**
         * The configuration object used by the cacher to populate its properties.
         * @var Configuration
         */
        protected Configuration $config;

        /**
         * The caching adapter used by the cacher to interact with the underlying storage mechanism for cache files.
         * @var AdapterInterface
         */
        protected AdapterInterface $adapter;

        /**
         * The tag manager used by a cacher to handle tag-based cache invalidation and management.
         * @var TagManager
         */
        protected TagManager $tagManager;

        /**
         * Creates a new cacher.
         * @param string|null $root The root directory of the cacher where cache files will be stored.
         * @param int|null $permission The permission used by the cacher to create files.
         * @param string|null $hashingAlgorithm The hashing algorithm used by the cacher to create files.
         * @param AdapterInterface|null $adapter The adapter to use for storage operations; defaults to `LocalAdapter`.
         * @param Configuration|null $config The configuration object used by the cacher.
         */
        public function __construct (
            ?string $root = null,
            ?int $permission = null,
            ?string $hashingAlgorithm = null,
            ?AdapterInterface $adapter = null,
            ?Configuration $config = null
        ) {
            $this->config = $config ?? Configuration::find() ?? new Configuration();
            Configuration::hydrate($this, $this->config);

            $this->root = PathUtils::getRelativePath(ROOT_DIR, $root ?? $this->root);

            if (isset($permission)) $this->permission = $permission;
            if (isset($hashingAlgorithm)) $this->hashingAlgorithm = $hashingAlgorithm;

            $this->adapter = $adapter ?? new LocalAdapter($this->permission);

            $this->tagManager = new TagManager($this);
        }

        /**
         * Reads the existing numeric value for a cache key, adjusts it by `$delta`, then writes
         * the result back. When the key is absent or stale, the counter is treated as `0`.
         *
         * TTL behaviour:
         * - When `$ttl` is `null` and the adapter does NOT implement `CounterInterface`, the
         *   remaining TTL of the existing entry is preserved so that counter updates do not
         *   accidentally reset the entry's lifetime.
         * - When `$ttl` is `null` and the adapter DOES implement `CounterInterface` (APCu, Redis),
         *   the cacher's configured default TTL is used. These adapters perform atomic operations
         *   natively and do not read the existing entry first, so the remaining TTL cannot be
         *   retrieved without an additional round-trip that would break atomicity.
         * - When an explicit `$ttl` is provided, it overrides the stored TTL for this write on
         *   all adapter types.
         *
         * NOTE: Atomicity is best-effort. On `LocalAdapter` the read-modify-write cycle is not
         * protected by a cross-process file lock. Use `ApcuAdapter` or `RedisAdapter` for workloads
         * that require strict counter atomicity (e.g. rate-limiting across multiple processes).
         * @param string $key The cache key to adjust.
         * @param int $delta The value to add (positive) or subtract (negative).
         * @param int|DateInterval|null $ttl Explicit TTL override, or `null` to preserve the existing TTL (non-atomic adapters only).
         * @return int The new counter value after the adjustment.
         * @throws NonNumericValueException If the existing cached value is not numeric.
         */
        private function adjustCounter (string $key, int $delta, int|DateInterval|null $ttl) : int {
            $path = $this->createPathFromKey($key);
            $resolvedTtl = $this->resolveTtl($ttl);

            # Delegate to the adapter's native atomic implementation when available.
            if ($this->adapter instanceof CounterInterface) {
                return $this->adapter->adjustCounter($path, $delta, $resolvedTtl);
            }

            # Best-effort read-modify-write fallback for adapters without native counter support.
            $current = 0;

            if ($this->adapter->exists($path)) {
                try {
                    $raw = $this->adapter->read($path);
                    $cache = unserialize($raw, ["allowed_classes" => [Cache::class]]);

                    if ($cache instanceof Cache && $cache->isFresh()) {
                        $value = $cache->getContent();

                        if (!is_numeric($value)) {
                            throw new NonNumericValueException("Cannot modify a non-numeric cache value for key \"$key\".");
                        }

                        $current = (int) $value;

                        if ($ttl === null) {
                            $resolvedTtl = $cache->getRemainingTime();
                        }
                    }
                }
                catch (NonNumericValueException $e) {
                    throw $e;
                }
                catch (Throwable) {
                    $current = 0;
                }
            }

            $newValue = $current + $delta;
            $this->set($key, $newValue, $resolvedTtl);
            return $newValue;
        }

        /**
         * Resolves a PSR-16-compatible TTL argument to a plain integer count of seconds.
         * - `null`          → the cacher's configured default TTL.
         * - `int`           → used as-is (seconds).
         * - `DateInterval`  → converted to an equivalent number of seconds by adding the
         *                     interval to the current moment and computing the difference.
         *                     This correctly handles variable-length intervals such as months
         *                     and years, which cannot be reduced to a fixed second count without
         *                     a reference date.
         * @param int|DateInterval|null $ttl The TTL value to resolve.
         * @return int The resolved TTL in seconds.
         */
        private function resolveTtl (int|DateInterval|null $ttl) : int {
            if ($ttl === null) return $this->ttl;
            if (is_int($ttl)) return $ttl;

            $now = new DateTimeImmutable();
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        /**
         * Clears the entire cache by deleting all files and directories within the root directory.
         * @return bool Whether the cache was successfully cleared.
         */
        public function clear () : bool {
            try {
                $result = $this->adapter->delete($this->getRootDirectory());
                if ($result) $this->tagManager->clearBuffer();
                return $result;
            }
            catch (Throwable $e) {
                return false;
            }
        }

        /**
         * Invalidates all cache items associated with any of the given tags.
         * @param string|array $tags A single tag or an array of tags to invalidate.
         * @return int The number of items successfully cleared.
         */
        public function clearByTags (string|array $tags) : int {
            $tags = (array) $tags;
            $count = 0;
            $deletedRelativePaths = [];

            foreach ($tags as $tag) {
                $hash = hash($this->hashingAlgorithm, $tag);
                $tagFile = $this->getAbsolutePath(PathUtils::join($this->tagManager->getTagDirectory(), "$hash." . $this->tagManager->getIndexExtension()));

                if (!$this->adapter->exists($tagFile)) continue;

                $raw = $this->adapter->read($tagFile);
                $relativePaths = array_unique(explode(PHP_EOL, trim($raw)));

                foreach ($relativePaths as $relativePath) {
                    $absolutePath = $this->getAbsolutePath($relativePath);
                    if (!$this->adapter->exists($absolutePath)) continue;
                    if ($this->adapter->delete($absolutePath)) {
                        $count++;
                        $deletedRelativePaths[] = $relativePath;
                    }
                }

                $this->adapter->delete($tagFile);
            }

            if (!empty($deletedRelativePaths)) {
                $registryFile = $this->getAbsolutePath($this->registryFile);

                if ($this->adapter->exists($registryFile)) {
                    $lines = explode(PHP_EOL, trim($this->adapter->read($registryFile)));
                    $lines = array_filter($lines, function ($line) use ($deletedRelativePaths) {
                        foreach ($deletedRelativePaths as $rel) {
                            if (str_starts_with($line, "$rel|")) return false;
                        }
                        return true;
                    });
                    $this->adapter->write($registryFile, implode(PHP_EOL, $lines) . PHP_EOL);
                }
            }

            return $count;
        }

        /**
         * Performs a deep maintenance of the cache system.
         * Removes expired files, prunes tag indices, and cleans up empty sharding directories.
         * @return array{files: int, indices: int, dirs: int} Statistics of the operation.
         */
        public function collectGarbage () : array {
            $stats = ["files" => 0, "indices" => 0, "dirs" => 0];
            $now = time();
            $registryFile = $this->getAbsolutePath($this->registryFile);

            if ($this->adapter->exists($registryFile)) {
                $raw = $this->adapter->read($registryFile);
                $entries = explode(PHP_EOL, trim($raw));
                $remaining = [];
                $deletedPaths = [];

                foreach ($entries as $entry) {
                    if (!str_contains($entry, '|')) continue;
                    [$path, $expiry] = explode('|', $entry);

                    if ($now > (int) $expiry) {
                        # Delete the physical file
                        if ($this->adapter->delete($this->getAbsolutePath($path))) {
                            $stats["files"]++;
                            $deletedPaths[] = $path;
                        }
                    } else {
                        $remaining[] = $entry;
                    }
                }

                $stats["indices"] = $this->tagManager->synchroniseIndices($deletedPaths);

                $this->adapter->write($registryFile, implode(PHP_EOL, $remaining) . PHP_EOL);
            }

            if ($this->adapter instanceof PrunableInterface) {
                $stats["dirs"] = $this->pruneEmptyDirectories();
            }

            return $stats;
        }

        /**
         * Converts a cache key into a file path, applying sharding if enabled.
         * @param string $key The cache key.
         * @param Configuration|null $config The configuration object to use for any necessary settings.
         * @return string The generated file path for the cache key.
         */
        public function createPathFromKey (string $key, ?Configuration $config = null) : string {
            $config = $config ?? $this->config;

            # 1. Extract configuration with defaults.
            $strict = $config->get("strict", $this->strict);
            $shardDepth = $config->get("shardDepth", $this->shardDepth);
            $shardLength = $config->get("shardLength", $this->shardLength);
            $cacheExtension = $config->get("cacheExtension", $this->cacheExtension);
            $minShardLength = $config->get("minShardLength", $this->minShardLength);
            $maxShardLength = $config->get("maxShardLength", $this->maxShardLength);
            $shardingEnabled = $config->get("shardingEnabled", $this->shardingEnabled);
            $hashingAlgorithm = $config->get("hashingAlgorithm", $this->hashingAlgorithm);

            $hash = hash($hashingAlgorithm, $key);
            $shards = [];

            if ($shardingEnabled) {
                # 2. Handle shard length (strict vs. clamped).
                if ($shardLength < $minShardLength || $shardLength > $maxShardLength) {
                    if ($strict) {
                        throw new InvalidShardConfigException("Shard length must be between {$minShardLength} and {$maxShardLength}.");
                    }
                    # Clamp the value if not strict.
                    $shardLength = max($minShardLength, min($shardLength, $maxShardLength));
                }

                # 3. Iterate based on depth.
                $currentIndex = 0;
                for ($i = 0; $i < $shardDepth; $i++) {
                    # Stop if we run out of hash characters.
                    if ($currentIndex + $shardLength > strlen($hash)) {
                        break; 
                    }

                    $shards[] = substr($hash, $currentIndex, $shardLength);
                    $currentIndex += $shardLength;
                }
            }

            # 4. Final assembly
            $shards[] = "$hash.$cacheExtension";
            return $this->getAbsolutePath(PathUtils::join(...$shards));
        }

        /**
         * Decrements a numeric cache value by the given step.
         *
         * If the key does not exist or has expired, the counter is treated as `0` before the
         * step is applied — yielding `-$step` as the initial value.
         *
         * The existing entry's TTL is preserved unless an explicit `$ttl` is provided.
         *
         * NOTE: Atomicity is best-effort. On `LocalAdapter` the read-modify-write cycle is not
         * protected by a cross-process file lock. Use `ApcuAdapter` or `RedisAdapter` for workloads
         * that require strict counter atomicity (e.g. rate-limiting across multiple processes).
         * @param string $key The cache key to decrement.
         * @param int $step The amount to subtract; should be a positive integer.
         * @param int|DateInterval|null $ttl Optional TTL override; `null` preserves the existing TTL.
         * @return int The new counter value after decrementing.
         * @throws NonNumericValueException If the stored value is not numeric.
         */
        public function decrement (string $key, int $step = 1, int|DateInterval|null $ttl = null) : int {
            return $this->adjustCounter($key, -$step, $ttl);
        }

        /**
         * Deletes a cache item by its unique key.
         * @param string $key The unique key of the cache item to delete.
         * @return bool Whether the item was successfully deleted.
         */
        public function delete (string $key) : bool {
            $path = $this->createPathFromKey($key);
            $deleted = $this->adapter->delete($path);

            if ($deleted) {
                $registryFile = $this->getAbsolutePath($this->registryFile);

                if ($this->adapter->exists($registryFile)) {
                    $relativePath = PathUtils::getRelativePath($this->getAbsolutePath(), $path);
                    $lines = explode(PHP_EOL, trim($this->adapter->read($registryFile)));
                    $lines = array_filter($lines, fn ($line) => !str_starts_with($line, "$relativePath|"));
                    $this->adapter->write($registryFile, implode(PHP_EOL, $lines) . PHP_EOL);
                }
            }

            return $deleted;
        }

        /**
         * Deletes multiple cache items by their unique keys.
         * Performs all file deletions in a single pass then updates the registry once — avoiding
         * the O(n²) registry I/O that would result from calling `delete()` in a loop.
         * @param iterable $keys A list of keys that can be deleted in a single operation.
         * @return bool Whether all items were successfully deleted.
         */
        public function deleteMultiple (iterable $keys) : bool {
            $success = true;
            $deletedRelativePaths = [];

            foreach ($keys as $key) {
                $path = $this->createPathFromKey($key);

                if (!$this->adapter->delete($path)) {
                    $success = false;
                    continue;
                }

                $deletedRelativePaths[] = PathUtils::getRelativePath($this->getAbsolutePath(), $path);
            }

            if (!empty($deletedRelativePaths)) {
                $registryFile = $this->getAbsolutePath($this->registryFile);

                if ($this->adapter->exists($registryFile)) {
                    $lines = explode(PHP_EOL, trim($this->adapter->read($registryFile)));
                    $lines = array_filter($lines, function ($line) use ($deletedRelativePaths) {
                        foreach ($deletedRelativePaths as $rel) {
                            if (str_starts_with($line, "$rel|")) return false;
                        }
                        return true;
                    });
                    $this->adapter->write($registryFile, implode(PHP_EOL, $lines) . PHP_EOL);
                }
            }

            return $success;
        }

        /**
         * Generates a fingerprint for a set of files.
         * @param string|string[] $files The paths to the files.
         * @param string|null $hashingAlgorithm The hashing algorith to use.
         * @return string A SHA-256 hash.
         */
        public function generateFingerprint (string|array $files, ?string $hashingAlgorithm = null) : string {
            $hashingAlgorithm ??= $this->hashingAlgorithm;

            $data = [];

            $files = is_array($files) ? $files : [$files];

            foreach ($files as $file) {
                $file = PathUtils::resolvePath($this->getRootDirectory(), $file, false);

                $mtime = filemtime($file) ?: 0;
                $hash = hash_file($hashingAlgorithm, $file) ?: '';
                $data[] = "$file:$mtime:$hash";
            }

            return hash($hashingAlgorithm, implode('|', $data));
        }

        /**
         * Obtains a cache item by its unique key.
         * @param string $key The unique key of the cache item to retrieve.
         * @param mixed $default Default value to return if the item does not exist or is stale.
         * @return mixed The cached value, or the default if not found or stale.
         */
        public function get (string $key, mixed $default = null) : mixed {
            $path = $this->createPathFromKey($key);
            
            if (!$this->adapter->exists($path)) {
                return $default;
            }

            try {
                $raw = $this->adapter->read($path);
                $cache = unserialize($raw, ["allowed_classes" => [Cache::class]]);

                if (!$cache instanceof Cache || !$cache->isFresh()) {
                    $this->delete($key);
                    return $default;
                }

                return $cache->getContent();
            }
            catch (Throwable $e) {
                $this->delete($key);
                return $default;
            }
        }

        /**
         * Gets the absolute path of the cache root or a subpath within it.
         * @param string $subPath An optional subpath to append to the root directory.
         * @return string The absolute path of the cache root or the specified subpath.
         */
        public function getAbsolutePath (string $subPath = ""): string {
            return PathUtils::join($this->getRootDirectory(), $subPath);
        }

        /**
         * Gets the currently integrated adapter.
         * @return AdapterInterface The currently integrated adapter.
         */
        public function getAdapter () : AdapterInterface {
            return $this->adapter;
        }
        
        /**
         * Gets a list of all cache files within the root directory, including those in subdirectories.
         * @return array A list of file paths for all cache files found.
         */
        public function getCacheFiles () : array {
            $allFiles = $this->adapter->list($this->getRootDirectory());
            return array_filter($allFiles, function ($path) {
                return str_ends_with($path, ".{$this->cacheExtension}") && 
                    !str_contains($path, DIRECTORY_SEPARATOR . $this->tagManager->getTagDirectory() . DIRECTORY_SEPARATOR);
            });
        }

        /**
         * Gets the configuration object used by a cacher to populate its properties.
         * @return Configuration The configuration object used by the cacher.
         */
        public function getConfig () : Configuration {
            return $this->config;
        }

        /**
         * Gets the hashing algorithm used by a cacher to create files.
         * @return string The hashing algorithm used by the cacher to create files.
         */
        public function getHashingAlgorithm () : string {
            return $this->hashingAlgorithm;
        }
        
        /**
         * Retrieves all cache items that match a specific tag.
         * Uses the tag index maintained by `TagManager` to enumerate only the entries associated
         * with the requested tag, avoiding a full-cache scan. The index is consulted for the set of
         * candidate paths; each candidate is then loaded, freshness-checked, and tag-verified before
         * being yielded. Stale index entries (paths for files that no longer exist) are silently skipped.
         * @param string $tag The tag to filter by.
         * @return iterable<string, mixed> A generator yielding key => content for every fresh, matching entry.
         */
        public function getItemsByTag (string $tag) : iterable {
            $hash = hash($this->hashingAlgorithm, $tag);
            $tagFile = $this->getAbsolutePath(
                PathUtils::join($this->tagManager->getTagDirectory(), "$hash." . $this->tagManager->getIndexExtension())
            );

            if (!$this->adapter->exists($tagFile)) return;

            try {
                $raw = $this->adapter->read($tagFile);
            }
            catch (Throwable $e) {
                return;
            }

            $relativePaths = array_unique(array_filter(array_map('trim', explode(PHP_EOL, $raw))));

            foreach ($relativePaths as $relativePath) {
                $absolutePath = $this->getAbsolutePath($relativePath);

                if (!$this->adapter->exists($absolutePath)) continue;

                try {
                    $fileRaw = $this->adapter->read($absolutePath);
                    $cache = unserialize($fileRaw, ["allowed_classes" => [Cache::class]]);

                    if ($cache instanceof Cache && $cache->isFresh() && $cache->hasTag($tag)) {
                        yield $cache->getKey() => $cache->getContent();
                    }
                }
                catch (Throwable $e) {
                    continue;
                }
            }
        }

        /**
         * Obtains multiple cache items by their unique keys.
         * @param iterable $keys A list of keys that can be obtained in a single operation.
         * @param mixed $default Default value to return for keys that do not exist.
         * @return iterable A list of key => value pairs.
         */
        public function getMultiple (iterable $keys, mixed $default = null) : iterable {
            foreach ($keys as $key) {
                yield $key => $this->get($key, $default);
            }
        }

        /**
         * Gets the root directory of a cacher.
         * @return string The root directory of the cacher.
         */
        public function getRootDirectory () : string {
            return PathUtils::join(ROOT_DIR, $this->root);
        }

        /**
         * Gets the shard depth used by a cacher to determine how many subdirectories will be created based on the hash of the cache key.
         * @return int The shard depth used by the cacher.
         */
        public function getShardDepth () : int {
            return $this->shardDepth;
        }

        /**
         * Gets the shard length used by a cacher to determine how many characters of the hash will be used for each subdirectory level in the sharding process.
         * @return int The shard length used by the cacher.
         */
        public function getShardLength () : int {
            return $this->shardLength;
        }

        /**
         * Gets the stats of the cache system, including total files, total size, storage root, and adapter used.
         * When the adapter implements `StatsProviderInterface` its own native statistics are returned.
         * For `LocalAdapter` (which does not implement that interface), the physical cache directory is
         * scanned instead. Both paths always include at least the minimum key set defined by the interface.
         * @return array An associative array containing cache statistics.
         */
        public function getStats () : array {
            if ($this->adapter instanceof StatsProviderInterface) {
                return $this->adapter->getStats();
            }

            $adapterName = (new ReflectionClass($this->adapter))->getShortName();
            $root = $this->getAbsolutePath();

            if (!is_dir($root)) {
                return [
                    "total_files" => 0,
                    "total_size" => "0 MB",
                    "storage_root" => $root,
                    "adapter" => $adapterName,
                    "status" => "Offline (Directory Missing)"
                ];
            }

            $cleanRoot = realpath($root) ?: $root;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            $count = 0;
            $size = 0;

            foreach ($it as $file) {
                if ($file->isFile()) {
                    $count++;
                    $size += $file->getSize();
                }
            }

            return [
                "total_files" => $count,
                "total_size" => round($size / 1024 / 1024, 2) . " MB",
                "storage_root" => $cleanRoot,
                "adapter" => $adapterName,
                "status" => "Online"
            ];
        }

        /**
         * Gets the tag manager used by a cacher to handle tag-based cache invalidation and management.
         * @return TagManager The tag manager used by the cacher.
         */
        public function getTagManager () : TagManager {
            return $this->tagManager;
        }
        
        /**
         * Gets the time-to-live in seconds of a cache file created by a cacher.
         * @return int The time-to-live of a cache file in seconds.
         */
        public function getTTL () : int {
            return $this->ttl;
        }

        /**
         * Checks if a cache item exists and is fresh by its unique key.
         * @param string $key The unique key of the cache item to check.
         * @return bool Whether the item exists and is fresh.
         */
        public function has (string $key) : bool {
            $path = $this->createPathFromKey($key);

            if (!$this->adapter->exists($path)) return false;

            try {
                $raw = $this->adapter->read($path);
                $cache = unserialize($raw, ["allowed_classes" => [Cache::class]]);
                return $cache instanceof Cache && $cache->isFresh();
            }
            catch (Throwable $e) {
                return false;
            }
        }

        /**
         * Checks if a cacher has an adapter integrated.
         * @return bool Whether an adapter is integrated.
         */
        public function hasAdapter () : bool {
            return isset($this->adapter);
        }

        /**
         * Increments a numeric cache value by the given step.
         *
         * If the key does not exist or has expired, the counter is treated as `0` before the
         * step is applied — yielding `$step` as the initial value. This makes `increment()` safe to
         * call without a preceding `set()`, which is the common pattern for rate-limiting counters.
         *
         * The existing entry's TTL is preserved unless an explicit `$ttl` is provided. Passing an
         * explicit TTL on the first call initialises the counter's lifetime; subsequent increments
         * without a `$ttl` will honour that original expiry.
         *
         * NOTE: Atomicity is best-effort. On `LocalAdapter` the read-modify-write cycle is not
         * protected by a cross-process file lock. Use `ApcuAdapter` or `RedisAdapter` for workloads
         * that require strict counter atomicity (e.g. rate-limiting across multiple processes).
         * @param string $key The cache key to increment.
         * @param int $step The amount to add; should be a positive integer.
         * @param int|DateInterval|null $ttl Optional TTL override; `null` preserves the existing TTL.
         * @return int The new counter value after incrementing.
         * @throws NonNumericValueException If the stored value is not numeric.
         */
        public function increment (string $key, int $step = 1, int|DateInterval|null $ttl = null) : int {
            return $this->adjustCounter($key, $step, $ttl);
        }

        /**
         * Recursively prunes empty shard directories within the cache root, excluding the tags directory.
         * Delegates to the adapter when it implements `PrunableInterface`; returns `0` for in-memory adapters
         * that have no directory tree to clean.
         * @param string|null $directory The absolute path of the directory to prune; defaults to the cache root.
         * @return int The number of directories removed.
         */
        public function pruneEmptyDirectories (?string $directory = null) : int {
            if (!$this->adapter instanceof PrunableInterface) return 0;

            $root = $directory ?? $this->getRootDirectory();
            return $this->adapter->pruneEmptyDirectories($root, [$this->tagManager->getTagDirectory()]);
        }

        /**
         * Rebuilds the registry by crawling the cache directory and updating the registry file with the current cache items
         * and their expiration times. This is useful for recovering from a corrupted registry or ensuring that the registry
         * is accurate after manual changes to cache files.
         * @return int The number of cache items indexed in the registry after rebuilding.
         */
        public function rebuildRegistry () : int {
            $cacheFiles = $this->getCacheFiles();
            $registryEntries = [];
            $count = 0;

            foreach ($cacheFiles as $path) {
                try {
                    $raw = $this->adapter->read($path);
                    $cache = unserialize($raw, ["allowed_classes" => [Cache::class]]);

                    if ($cache instanceof Cache) {
                        $relativePath = PathUtils::getRelativePath($this->getAbsolutePath(), $path);
                        $registryEntries[] = "{$relativePath}|{$cache->getExpiryTimestamp()}";
                        $count++;
                    }
                }
                catch (Throwable $e) {
                    continue;
                }
            }

            if (!empty($registryEntries)) {
                $registryFile = $this->getAbsolutePath($this->registryFile);
                $this->adapter->write($registryFile, implode(PHP_EOL, $registryEntries) . PHP_EOL);
            }

            return $count;
        }

        /**
         * Retrieves a cached value, computing and storing it on a miss.
         *
         * When the adapter implements `LockableInterface`, a try-lock / double-check / release
         * pattern is applied to prevent cache stampedes:
         *
         * 1. Fast path: return the cached value immediately on a hit.
         * 2. Try to acquire an exclusive lock for `$key`.
         * 3. Lock winner: re-read the cache (double-check). Another process may have populated
         *    the entry while the current one was waiting. If still a miss, compute, store, and
         *    return the value; release the lock in a `finally` block.
         * 4. Lock loser: spin with a 50 ms back-off for up to `$lockWaitMs` milliseconds, retrying
         *    the cache read after each pause. If the entry appears, return it. If the lock timeout
         *    is exceeded, fall through and compute the value independently (best-effort) to avoid
         *    an indefinite stall.
         *
         * When the adapter does not implement `LockableInterface`, the original best-effort
         * compute-and-store behaviour is used. The stampede risk is acceptable for those adapters
         * (typically `LocalAdapter` in single-process or low-concurrency scenarios).
         *
         * A `stdClass` sentinel distinguishes a stored `null` value from a cache miss, so
         * caching `null` works correctly throughout.
         * @param string $key The unique cache key.
         * @param callable $callback A closure that produces the value when the cache misses.
         * @param int|DateInterval|null $ttl Optional time-to-live; `null` uses the cacher's default.
         * @param int $lockWaitMs Maximum milliseconds to spin waiting for the lock (default: 5 000 ms).
         * @param array $tags Optional tags to associate with the stored entry.
         * @param array $metadata Optional metadata to associate with the stored entry.
         * @return mixed The cached or freshly-computed value.
         */
        public function remember (string $key, callable $callback, int|DateInterval|null $ttl = null, int $lockWaitMs = 5000, array $tags = [], array $metadata = []) : mixed {
            $miss = new stdClass();

            $value = $this->get($key, $miss);
            if ($value !== $miss) return $value;

            if (!$this->adapter instanceof LockableInterface) {
                $value = $callback();
                $this->set($key, $value, $ttl, $tags, $metadata);
                return $value;
            }

            # Lock acquired: compute, store, release.
            if ($this->adapter->acquireLock($key)) {
                try {
                    $value = $this->get($key, $miss);
                    if ($value !== $miss) return $value;

                    $value = $callback();
                    $this->set($key, $value, $ttl, $tags, $metadata);
                    return $value;
                }
                finally {
                    $this->adapter->releaseLock($key);
                }
            }

            # Lock lost: spin with back-off until the entry appears or the wait budget is exhausted.
            $elapsed = 0;
            $interval = 50;

            while ($elapsed < $lockWaitMs) {
                usleep($interval * 1000);
                $elapsed += $interval;

                $value = $this->get($key, $miss);
                if ($value !== $miss) return $value;
            }

            # Budget exhausted — compute independently to avoid an indefinite stall.
            $value = $callback();
            $this->set($key, $value, $ttl, $tags, $metadata);
            return $value;
        }

        /**
         * Persists a value in the cache, uniquely referenced by a key.
         * @param string $key The unique key of the cache item to set.
         * @param mixed $value The value to store in the cache.
         * @param int|DateInterval|null $ttl Optional time-to-live for this item; an `int` is treated as seconds,
         *                                   a `DateInterval` is converted to seconds, and `null` uses the default TTL.
         * @param array $tags Optional array of tags to associate with this cache item for easier invalidation.
         * @param array $metadata Optional array of metadata to associate with this cache item.
         * @return bool Whether the item was successfully stored.
         */
        public function set (string $key, mixed $value, int|DateInterval|null $ttl = null, array $tags = [], array $metadata = []) : bool {
            $path = $this->createPathFromKey($key);
            $ttl = $this->resolveTtl($ttl);
            $cache = new Cache($path, $value, $ttl, $tags, $metadata, null, $key);

            try {
                $this->adapter->write($path, serialize($cache));

                $registryFile = $this->getAbsolutePath($this->registryFile);
                $relativePath = PathUtils::getRelativePath($this->getAbsolutePath(), $path);
                $newEntry = "$relativePath|{$cache->getExpiryTimestamp()}";

                if ($this->adapter->exists($registryFile)) {
                    $existing = explode(PHP_EOL, trim($this->adapter->read($registryFile)));
                    $existing = array_filter($existing, fn ($line) => !str_starts_with($line, "$relativePath|"));
                    $existing[] = $newEntry;
                    $this->adapter->write($registryFile, implode(PHP_EOL, $existing) . PHP_EOL);
                } else {
                    $this->adapter->append($registryFile, $newEntry . PHP_EOL);
                }

                if (!empty($tags)) {
                    $this->tagManager->registerTags($path, $tags);
                }
                return true;
            }
            catch (Throwable $e) {
                return false;
            }
        }

        /**
         * Integrates the provided adapter by ensuring it implements the required AdapterInterface.
         * @param object $adapter The adapter to integrate.
         * @param array|null $proxyMap An optional mapping for proxying if the adapter does not directly implement the interface.
         * @return static The current instance for method chaining.
         * @throws InvalidAdapterException If the provided adapter does not implement the required interface.
         */
        public function setAdapter (object $adapter, ?array $proxyMap = null) : static {
            if ($adapter instanceof AdapterInterface) {
                $this->adapter = $adapter;
                return $this;
            }

            if (!class_exists($proxyClass = "\\Wingman\\Helix\\Proxy")) {
                throw new InvalidAdapterException("Provided adapter does not implement the required AdapterInterface.");
            }

            $contractClass = "\\Wingman\\Helix\\Contract";
            $contract = $contractClass::fromInterface(AdapterInterface::class);
            $satisfied = $contract->isSatisfiedBy($adapter);

            if (!$satisfied && $proxyMap) {
                $adapter = $proxyClass::from($adapter, $proxyMap);

                foreach (["append", "delete", "exists", "list", "read", "write"] as $method) {
                    if (!$adapter->hasMethod($method)) {
                        throw new InvalidAdapterException("Proxy mapping is missing required method: $method");
                    }
                }

                $satisfied = true;
            }

            if (!$satisfied) {
                throw new InvalidAdapterException("Provided adapter does not implement the required AdapterInterface.");
            }

            $this->adapter = $adapter;
            return $this;
        }

        /**
         * Persists multiple values in the cache, each referenced by its own key.
         * @param iterable $values A key-value map of items to store.
         * @param int|DateInterval|null $ttl Optional time-to-live for all items; an `int` is treated as seconds,
         *                                    a `DateInterval` is converted to seconds, and `null` uses the default TTL.
         * @param array $tags Optional array of tags to associate with every cache item.
         * @param array $metadata Optional array of metadata to associate with every cache item.
         * @return bool Whether all items were successfully stored.
         */
        public function setMultiple (iterable $values, int|DateInterval|null $ttl = null, array $tags = [], array $metadata = []) : bool {
            $success = true;
            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl, $tags, $metadata)) {
                    $success = false;
                }
            }
            return $success;
        }
    }
?>