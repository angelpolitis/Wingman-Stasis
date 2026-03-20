# Wingman — Stasis

A self-contained caching engine for PHP with a swappable adapter model, configurable key sharding, tag-based invalidation, PSR-16-compatible method signatures, and atomic increment/decrement counters.

---

## Requirements

- PHP **8.0** or later
- **Wingman/Cortex** *(optional)* — enables `#[Configurable]` attribute-driven configuration hydration
- **Wingman/Synapse** *(optional)* — enables DI container integration via `Provider` and `CacherFactory`
- **Wingman/Console** *(optional)* — enables the `cache:repair` maintenance command
- **Wingman/Helix** *(optional)* — enables proxy-based adapter integration via `setAdapter()`
- **ext-apcu** *(optional)* — required by `ApcuAdapter`
- **ext-redis** *(optional)* — required by `RedisAdapter`
- **ext-memcached** *(optional)* — required by `MemcachedAdapter`

---

## Installation

Install the package via Composer:

```bash
composer require wingman/stasis

```

In addition, you can download the package directly and use its autoloader (`autoload.php`), which registers a PSR-style class map for the `Wingman\Stasis` namespace and loads any mandatory dependencies declared in `manifest.json`.

---

## Quick Start

```php
use Wingman\Stasis\Cacher;

// Instantiate with all defaults — uses LocalAdapter and stores under temp/cache.
$cacher = new Cacher();

// Store a value for 10 minutes.
$cacher->set('user.42', $user, 600);

// Retrieve it — returns null on a miss.
$user = $cacher->get('user.42');

// Retrieve and compute on a miss (cache-aside pattern).
$user = $cacher->remember('user.42', fn () => $repository->find(42), 600);

// Check existence.
if ($cacher->has('user.42')) { ... }

// Delete.
$cacher->delete('user.42');
```

---

## Adapters

Stasis ships four adapters. Swap them by passing an adapter instance to the constructor or to `setAdapter()`.

| Adapter | Backend | Atomic counters | Distributed |
| --- | --- | --- | --- |
| `LocalAdapter` | Local filesystem | ✗ | ✗ |
| `ApcuAdapter` | APCu shared memory | ✓ | ✗ |
| `RedisAdapter` | Redis server | ✓ | ✓ |
| `MemcachedAdapter` | Memcached cluster | ✗ | ✓ |

```php
use Wingman\Stasis\Cacher;
use Wingman\Stasis\Adapters\RedisAdapter;
use Redis;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cacher = new Cacher(adapter: new RedisAdapter($redis));
```

See [docs/adapters.md](docs/adapters.md) for full adapter documentation.

---

## Tagging

Associate arbitrary tags with stored entries and invalidate all matching entries in one call.

```php
// Store entries with tags.
$cacher->set('user.42', $user, 3600, tags: ['users', 'admin']);
$cacher->set('user.99', $user2, 3600, tags: ['users']);

// Invalidate all entries tagged "users".
$cacher->clearByTags('users'); // returns count of deleted items

// Retrieve all fresh entries that carry a specific tag.
foreach ($cacher->getItemsByTag('admin') as $location => $content) {
    // ...
}
```

See [docs/tagging.md](docs/tagging.md).

---

## Counters

Atomically increment and decrement numeric values. `ApcuAdapter` and `RedisAdapter` delegate to their native atomic operations — safe for rate-limiting across multiple processes.

```php
// Rate-limiter: allow 100 requests per minute.
$count = $cacher->increment("rate:ip:{$ip}", 1, 60);

if ($count > 100) {
    // Throttle.
}

// Decrement.
$cacher->decrement('stock.item.7', 1);
```

---

## Stampede Protection

`remember()` applies a try-lock / double-check / release pattern when the adapter implements `LockableInterface` — preventing multiple processes from computing the same expensive value simultaneously.

```php
$result = $cacher->remember(
    key: 'dashboard.stats',
    callback: fn () => $db->computeExpensiveStats(),
    ttl: 300,
    lockWaitMs: 5000
);
```

---

## Configuration

The constructor accepts explicit parameters or, when Wingman Cortex is installed, reads from a `Configuration` instance whose properties are annotated with `#[Configurable]` keys.

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `cacher.root` | `string` | `temp/cache` | Cache root directory (relative to the package root) |
| `cacher.permission` | `int` | `0755` | Directory and file creation permission |
| `cacher.hashingAlgorithm` | `string` | `sha256` | Hash algorithm used to derive file paths from keys |
| `cacher.ttl` | `int` | `86400` | Default time-to-live in seconds |
| `cacher.strict` | `bool` | `false` | Throw on invalid shard config instead of clamping |
| `cacher.shardingEnabled` | `bool` | `true` | Enable key sharding into subdirectories |
| `cacher.shardDepth` | `int` | `2` | Number of shard directory levels |
| `cacher.shardLength` | `int` | `2` | Characters of the hash used per shard level |
| `cacher.cacheExtension` | `string` | `cache` | File extension for cache files |
| `cacher.registryFile` | `string` | `registry.idx` | Filename of the expiry registry |
| `cacher.loggingEnabled` | `bool` | `false` | Log cache operations (not yet wired) |
| `cacher.tagDirectory` | `string` | `tags` | Tag index subdirectory name |
| `cacher.indexExtension` | `string` | `idx` | File extension for tag index files |
| `cacher.maxPerformanceMode` | `bool` | `false` | Blindly append tag entries without duplicate checking |

See [docs/configuration.md](docs/configuration.md) for detailed descriptions and Cortex integration.

---

## Maintenance

Run the built-in repair command to rebuild the registry, tag indices, and prune empty directories after manual changes:

```bash
wingman cache:repair
wingman cache:repair --force   # skip confirmation prompt
```

Or call the API directly:

```php
$cacher->rebuildRegistry();
$cacher->getTagManager()->rebuildTagIndices();
$cacher->collectGarbage();
```

See [docs/garbage-collection.md](docs/garbage-collection.md).

---

## Further Reading

- [Architecture Overview](docs/overview.md)
- [Adapters](docs/adapters.md)
- [Configuration](docs/configuration.md)
- [Tagging](docs/tagging.md)
- [Garbage Collection & Maintenance](docs/garbage-collection.md)
- [Bridge Classes](docs/bridges.md)
- [Console Commands](docs/console.md)
- [API Reference](docs/api-reference.md)

---

## Licence

This project is licensed under the **Mozilla Public License 2.0 (MPL 2.0)**.

Wingman Stasis is part of the **Wingman Framework**, Copyright (c) 2025–2026 Angel Politis.

For the full licence text, please see the [LICENSE](LICENSE) file.
