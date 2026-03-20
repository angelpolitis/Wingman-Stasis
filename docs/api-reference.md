# API Reference

Complete reference for every public class and interface in Stasis.

---

## Table of Contents

- [`Cacher`](#cacher)
- [`Cache`](#cache)
- [`TagManager`](#tagmanager)
- [`PathUtils`](#pathutils)
- [Interfaces](#interfaces)
  - [`AdapterInterface`](#adapterinterface)
  - [`CounterInterface`](#counterinterface)
  - [`LockableInterface`](#lockableinterface)
  - [`PrunableInterface`](#prunableinterface)
  - [`StatsProviderInterface`](#statsproviderinterface)
- [Exceptions](#exceptions)

---

## `Cacher`

**Namespace:** `Wingman\Stasis`

The central caching engine. Wraps an `AdapterInterface` implementation and provides the application-facing API: key/value storage, batch operations, tagging, atomic counters, stampede protection, and garbage collection.

### Constructor

```php
public function __construct (
    ?string $root = null,
    ?int $permission = null,
    ?string $hashingAlgorithm = null,
    ?AdapterInterface $adapter = null,
    ?Configuration $config = null
)
```

All parameters are optional and fall back to their configured defaults when `null`. A `LocalAdapter` is created automatically when no adapter is provided and no adapter is set via `setAdapter()` before first use.

When Cortex is installed, every `#[Configurable("cacher.*")]`-annotated property is hydrated from the application configuration before any constructor argument is applied.

---

### Methods

#### `clear() : bool`

Deletes the entire cache root directory and its contents. The registry and tag indices are removed as part of the operation. Returns `true` on success.

---

#### `clearByTags(string|array $tags) : int`

Deletes all cache entries carrying any of the given tags. Returns the number of entries removed.

```php
$removed = $cacher->clearByTags(["products", "homepage"]);
```

---

#### `collectGarbage() : array`

Scans the registry for entries whose expiry timestamp has passed, removes the corresponding cache files, and purges their entries from the registry. If the adapter implements `PrunableInterface`, empty directories are removed afterwards.

Returns an associative array:

```php
[
    "deleted" => 42,   // files removed
    "freed" => 8192, // bytes freed
    "pruned" => 7,    // empty directories removed
    "remaining" => 158  // entries left in registry
]
```

---

#### `createPathFromKey(string $key, ?Configuration $config = null) : string`

Derives the relative filesystem path for a given cache key using the active hashing algorithm and sharding configuration. Accepts an optional alternative `Configuration` object for cases where the path must be resolved in the context of a different store.

---

#### `decrement(string $key, int $step = 1, int|DateInterval|null $ttl = null) : int`

Decrements a numeric cache entry by `$step`. Delegates to `CounterInterface::adjustCounter()` when the adapter supports it; otherwise performs a read-modify-write. Creates the entry if it does not exist (initial value `0`). Returns the new value.

Throws `NonNumericValueException` if the stored value is not numeric.

---

#### `delete(string $key) : bool`

Deletes the entry for the given key. Returns `true` when the entry was present and removed, `false` if the key was not found.

---

#### `deleteMultiple(iterable $keys) : bool`

Deletes a collection of keys. Returns `true` if all deletions succeeded.

---

#### `generateFingerprint(string|array $files, ?string $hashingAlgorithm = null) : string`

Generates a cache fingerprint by hashing the contents of one or more files. Useful for asset versioning or template cache invalidation. Uses the active hashing algorithm unless `$hashingAlgorithm` is given.

```php
$fingerprint = $cacher->generateFingerprint([
    "templates/home.mph",
    "templates/layout.mph"
]);
```

---

#### `get(string $key, mixed $default = null) : mixed`

Retrieves the content of a cache entry. Returns `$default` if the key is not found or the entry has expired.

---

#### `getAbsolutePath(string $subPath = "") : string`

Returns the absolute filesystem path to the cache root, optionally joined with `$subPath`.

---

#### `getAdapter() : AdapterInterface`

Returns the active adapter. Throws `InvalidAdapterException` if no adapter has been set and the default `LocalAdapter` cannot be constructed (e.g. the root directory is unwritable).

---

#### `getCacheFiles() : array`

Returns an array of relative paths for every `.cache` file currently stored.

---

#### `getConfig() : Configuration`

Returns the `Configuration` instance used to hydrate the cacher at construction time.

---

#### `getHashingAlgorithm() : string`

Returns the hashing algorithm used for key fingerprinting (e.g. `"sha256"`).

---

#### `getItemsByTag(string $tag) : iterable`

Returns an iterable of `Cache` objects for all entries carrying `$tag`. Entries that have expired are skipped.

---

#### `getMultiple(iterable $keys, mixed $default = null) : iterable`

Retrieves an iterable of key → value pairs. Missing or expired keys yield `$default`.

---

#### `getRootDirectory() : string`

Returns the relative cache root path (e.g. `"temp/cache"`).

---

#### `getShardDepth() : int`

Returns the number of directory levels used for sharding.

---

#### `getShardLength() : int`

Returns the number of hash characters used per shard level.

---

#### `getStats() : array`

Returns adapter-level statistics. Requires the adapter to implement `StatsProviderInterface`. The returned array always includes the keys: `total_keys`, `total_size`, `hits`, `misses`, `adapter`, `status`.

---

#### `getTagManager() : TagManager`

Returns the `TagManager` instance, constructing it lazily on first call.

---

#### `getTTL() : int`

Returns the default TTL in seconds.

---

#### `has(string $key) : bool`

Returns `true` if a non-expired entry exists for the given key.

---

#### `hasAdapter() : bool`

Returns `true` if an adapter has been explicitly set.

---

#### `increment(string $key, int $step = 1, int|DateInterval|null $ttl = null) : int`

Increments a numeric cache entry by `$step`. Mirrors the behaviour of `decrement()`.

---

#### `pruneEmptyDirectories(?string $directory = null) : int`

Recursively removes empty directories under `$directory` (defaults to the cache root). Requires the adapter to implement `PrunableInterface`. Returns the number of directories pruned.

---

#### `rebuildRegistry() : int`

Scans all `.cache` files, reads each file's TTL and creation date, and regenerates the registry from scratch. Returns the number of entries written.

---

#### `remember(string $key, callable $callback, int|DateInterval|null $ttl = null, int $lockWaitMs = 5000, array $tags = [], array $metadata = []) : mixed`

Returns the cached value for `$key` if it exists; otherwise calls `$callback`, stores the result, and returns it.

When the adapter implements `LockableInterface`, a try-lock / double-check / release pattern is used to prevent cache stampedes:

1. Try to acquire an exclusive lock.
2. If acquired — compute, store, release.
3. If not acquired — wait up to `$lockWaitMs` milliseconds, then re-check the cache (a concurrent request may have already populated it).

```php
$user = $cacher->remember("user.42", function () use ($db) {
    return $db->find(User::class, 42);
}, ttl: 3600, tags: ["users"]);
```

---

#### `set(string $key, mixed $value, int|DateInterval|null $ttl = null, array $tags = [], array $metadata = []) : bool`

Stores `$value` under `$key`. Returns `true` on success.

---

#### `setAdapter(object $adapter, ?array $proxyMap = null) : static`

Sets the adapter. If `$proxyMap` is provided, the adapter is wrapped in a dynamic proxy that delegates each named method to a different adapter instance, enabling per-operation backend routing.

Throws `InvalidAdapterException` if `$adapter` does not implement `AdapterInterface`.

---

#### `setMultiple(iterable $values, int|DateInterval|null $ttl = null, array $tags = [], array $metadata = []) : bool`

Stores multiple key → value pairs in a single operation. Returns `true` if all writes succeeded.

---

## `Cache`

**Namespace:** `Wingman\Stasis`

Immutable value object representing a single cached entry. Implements `JsonSerializable`.

### Constructor

```php
public function __construct (
    string $location,
    mixed $content,
    int $ttl,
    array $tags = [],
    array $metadata = [],
    ?DateTimeInterface $creationDate = null
)
```

`$location` is the storage path relative to the cache root. `$creationDate` defaults to `now` when `null`.

### Methods

| Method | Returns | Description |
| --- | --- | --- |
| `getContent()` | `mixed` | The stored value. |
| `getCreationDate()` | `DateTimeImmutable` | When the entry was written. |
| `getExpiryDate()` | `DateTimeImmutable` | Creation date + TTL. `null` for TTL = 0 entries. |
| `getExpiryTimestamp()` | `int` | Unix timestamp of expiry. `0` for never-expire. |
| `getLocation()` | `string` | Relative storage path. |
| `getMetadata()` | `array` | Application-level annotations. |
| `getRemainingTime(?DateTimeInterface $now = null)` | `int` | Seconds until expiry. `0` if expired. `PHP_INT_MAX` if TTL = 0. |
| `getTags()` | `array` | All tag strings carried by this entry. |
| `getTTL()` | `int` | Lifetime in seconds. `0` = never expires. |
| `hasTag(string $tag)` | `bool` | Whether the entry carries `$tag`. |
| `isExpired(DateTimeInterface\|string\|null $date = null)` | `bool` | Whether the entry has expired as of `$date` (default: now). |
| `isFresh(DateTimeInterface\|string\|null $date = null)` | `bool` | Inverse of `isExpired()`. |
| `jsonSerialize()` | `mixed` | Serialises the entry to a plain array. |

---

## `TagManager`

**Namespace:** `Wingman\Stasis`

Manages tag index files on behalf of `Cacher`. Accessed via `$cacher->getTagManager()`.

### Constructor

```php
public function __construct(Cacher $cacher)
```

Configuration is read from the `Cacher` instance and hydrated via `Configuration::hydrate()`.

### Methods

#### `getIndexExtension() : string`

Returns the file extension used for tag index files (default: `"idx"`).

---

#### `getTagDirectory() : string`

Returns the directory name used for tag indices (default: `"tags"`).

---

#### `rebuildTagIndices() : int`

Scans all cache files, reads each file's tag list, and regenerates every tag index file from scratch. Returns the number of index entries written.

---

#### `registerTags(string $cachePath, array $tags) : static`

Associates the given `$cachePath` with each tag in `$tags`. In standard mode this reads the existing index to avoid duplicates before appending; in maximum-performance mode it appends unconditionally and relies on `synchroniseIndices()` to deduplicate later.

---

#### `synchroniseIndices(array $knownDeleted = []) : int`

Removes stale paths from all tag index files — both paths in `$knownDeleted` and paths that no longer exist on the filesystem. Returns the total number of stale entries removed.

---

## `PathUtils`

**Namespace:** `Wingman\Stasis`

Static utility class for safe path manipulation.

### Methods

#### `fix(?string $path, string $new = DS, array $old = [...]) : ?string`

Normalises directory separators. Replaces all occurrences of any separator in `$old` with `$new`. Returns `null` when `$path` is `null`.

---

#### `forceTrailingSeparator(string $path, string $separator = DS, array $old = [...]) : string`

Normalises separators and ensures the path ends with exactly one trailing separator.

---

#### `getRelativePath(string $from, string $to) : string`

Computes the shortest relative path from directory `$from` to `$to` using `../` traversal steps as necessary.

---

#### `join(string ...$pathFragments) : string`

Joins one or more path fragments with the native directory separator, collapsing redundant separators.

---

#### `resolvePath(string $root, string $path, bool $strict = true) : string`

Resolves `$path` relative to `$root`, returning the canonical absolute path. Throws `PathEscapeException` when `$strict` is `true` and the resolved path falls outside `$root`.

---

## Interfaces

### `AdapterInterface`

**Namespace:** `Wingman\Stasis\Interfaces`

Core storage contract that every adapter must implement.

| Method | Returns | Description |
| --- | --- | --- |
| `append(string $path, string $contents)` | `bool` | Appends `$contents` to the file at `$path`. |
| `delete(string $path)` | `bool` | Removes the file at `$path`. |
| `exists(string $path)` | `bool` | Returns whether the file exists. |
| `list(string $path)` | `array` | Lists all keys/paths under `$path`. |
| `read(string $path)` | `string` | Reads and returns the full file contents. |
| `write(string $path, string $contents)` | `bool` | Writes `$contents` to `$path`, creating it if necessary. |

---

### `CounterInterface`

**Namespace:** `Wingman\Stasis\Interfaces`

Allows an adapter to expose native atomic counter operations.

| Method | Returns | Description |
| --- | --- | --- |
| `adjustCounter(string $path, int $delta, int $ttl)` | `int` | Atomically adds `$delta` (positive or negative) to the stored integer and returns the new value. |

---

### `LockableInterface`

**Namespace:** `Wingman\Stasis\Interfaces`

Allows an adapter to participate in stampede protection.

| Method | Returns | Description |
| --- | --- | --- |
| `acquireLock(string $key, int $ttl = 30)` | `bool` | Attempts a non-blocking exclusive lock. Returns `true` if acquired. |
| `releaseLock(string $key)` | `bool` | Releases a previously acquired lock. |

---

### `PrunableInterface`

**Namespace:** `Wingman\Stasis\Interfaces`

Allows an adapter to remove empty directories after deletion.

| Method | Returns | Description |
| --- | --- | --- |
| `pruneEmptyDirectories(string $rootDir, array $excluded = [])` | `int` | Recursively removes empty directories under `$rootDir`. Returns the count removed. |

---

### `StatsProviderInterface`

**Namespace:** `Wingman\Stasis\Interfaces`

Allows an adapter to expose backend-level statistics.

| Method | Returns | Description |
| --- | --- | --- |
| `getStats()` | `array` | Returns statistics. Must include: `total_keys`, `total_size`, `hits`, `misses`, `adapter`, `status`. |

---

## Exceptions

All exceptions are in the `Wingman\Stasis\Exceptions` namespace. Each class also implements the `Exception` marker interface so it can be caught uniformly.

| Class | Extends | Thrown when |
| --- | --- | --- |
| `InvalidAdapterException` | `InvalidArgumentException` | An object that does not implement `AdapterInterface` is passed to `setAdapter()`. |
| `InvalidShardConfigException` | `InvalidArgumentException` | `shardDepth × shardLength` exceeds the hash length for the chosen algorithm. |
| `MissingDependencyException` | `RuntimeException` | An optional dependency (e.g. APCu, Redis, Memcached) is unavailable at runtime. |
| `NonNumericValueException` | `InvalidArgumentException` | `increment()` or `decrement()` is called on a key whose stored value is not numeric. |
| `PathEscapeException` | `RuntimeException` | `PathUtils::resolvePath()` detects a traversal attempt outside the permitted root. |
| `StorageException` | `RuntimeException` | A read or write operation fails at the adapter level. |
