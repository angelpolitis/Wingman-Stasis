# Adapters

Stasis separates the caching logic from the storage backend through the `AdapterInterface`. Each adapter targets a specific storage medium; the `Cacher` class remains identical regardless of which adapter is active.

---

## Table of Contents

- [Choosing an Adapter](#choosing-an-adapter)
- [`LocalAdapter`](#localadapter)
- [`ApcuAdapter`](#apcuadapter)
- [`RedisAdapter`](#redisadapter)
- [`MemcachedAdapter`](#memcachedadapter)
- [Writing a Custom Adapter](#writing-a-custom-adapter)

---

## Choosing an Adapter

| Adapter | When to use |
|---|---|
| `LocalAdapter` | Default. Single-server apps, CLI workers, and any case where a shared-memory or networked cache is unavailable. |
| `ApcuAdapter` | High-throughput web requests on a single server; zero network latency; data is lost on PHP-FPM restart. |
| `RedisAdapter` | Multi-server deployments; horizontal scaling; persistence; pub/sub or Lua-script extensions. |
| `MemcachedAdapter` | Multi-server deployments with a well-established Memcached cluster already in place. |

---

## `LocalAdapter`

**Namespace:** `Wingman\Stasis\Adapters` **Implements:** `AdapterInterface`, `LockableInterface`, `PrunableInterface`

Stores cache entries as files on the local filesystem. No constructor arguments are required; the adapter is instantiated automatically by `Cacher` when no explicit adapter is set.

### Locking

Exclusive locks are acquired with `flock(LOCK_EX | LOCK_NB)` on a dedicated sidecar file stored in `sys_get_temp_dir()`. The sidecar filename includes the cache-key hash to avoid collisions. Locks are released in the same request with `releaseLock()` or cleaned up naturally by PHP's request teardown.

### Pruning

`pruneEmptyDirectories()` performs a post-order depth-first scan of the cache root and removes any directory that contains no files. A configurable exclusion list prevents accidental removal of the `tags/` subdirectory or other managed directories.

### Example

```php
use Wingman\Stasis\Cacher;

// LocalAdapter is the default; no explicit adapter setup is needed.
$cacher = new Cacher(root: "temp/cache");
$cacher->set("user.42", $user, ttl: 3600);
```

---

## `ApcuAdapter`

**Namespace:** `Wingman\Stasis\Adapters` **Implements:** `AdapterInterface`, `CounterInterface`, `LockableInterface`, `StatsProviderInterface`

Stores cache entries in APCu shared memory. Data is shared across all worker processes on a single server and persists for the life of the PHP-FPM pool. No external services or credentials are required.

### Requirements

- The `apcu` PHP extension must be installed and enabled.
- `apc.enabled = 1` in `php.ini` (and `apc.enable_cli = 1` for CLI usage).
- Throws `StorageException` at construction when APCu is unavailable.

### Locking

`apcu_add()` is used as an atomic compare-and-set primitive. The lock key includes the cache key hash; `apcu_delete()` releases the lock.

### Counters

`apcu_inc()` and `apcu_dec()` provide atomic increment/decrement without a separate `CounterInterface` read-modify-write cycle, making them safe under concurrent access.

### Constructor

```php
public function __construct ()
```

No arguments. APCu availability is checked at construction.

### Example

```php
use Wingman\Stasis\Adapters\ApcuAdapter;
use Wingman\Stasis\Cacher;

$cacher = new Cacher();
$cacher->setAdapter(new ApcuAdapter());

$cacher->set("session.token", $token, ttl: 900);
$cacher->increment("api.requests", step: 1, ttl: 86400);
```

---

## `RedisAdapter`

**Namespace:** `Wingman\Stasis\Adapters` **Implements:** `AdapterInterface`, `CounterInterface`, `LockableInterface`, `StatsProviderInterface`

Stores cache entries in a Redis server or cluster using the `ext-redis` PHP extension. Each entry is stored as a plain Redis string; the native `INCRBY` / `DECRBY` commands are used for atomic counter operations.

### Requirements

- The `ext-redis` PHP extension must be installed.
- A `Redis` instance that is already connected; connection management is the caller's responsibility.

### Constructor

```php
public function __construct (
    Redis $redis,
    string $prefix = "wingman_cache:",
    int $defaultTtl = 0
)
```

| Parameter | Description |
|---|---|
| `$redis` | A connected `Redis` instance. |
| `$prefix` | Key prefix added to every Redis key to avoid collisions with other applications. |
| `$defaultTtl` | Fallback TTL in seconds when a write is performed with TTL = 0. `0` means no expiry at the Redis level. |

### Locking

Locks are implemented with the Redis atomic `SET key value NX PX ttl_in_ms` command. `NX` ensures only one caller succeeds; `PX` provides automatic expiry so locks do not outlive a crashed process.

### Key Enumeration

`list()` uses a non-blocking `SCAN` cursor loop with the adapter prefix as a filter pattern, avoiding the blocking `KEYS *` command.

### Example

```php
use Redis;
use Wingman\Stasis\Adapters\RedisAdapter;
use Wingman\Stasis\Cacher;

$redis = new Redis();
$redis->connect("127.0.0.1", 6379);

$cacher = new Cacher();
$cacher->setAdapter(new RedisAdapter($redis, prefix: "myapp:", defaultTtl: 86400));

$cacher->set("user.42", $user, ttl: 3600);
$stats = $cacher->getStats();
```

---

## `MemcachedAdapter`

**Namespace:** `Wingman\Stasis\Adapters` **Implements:** `AdapterInterface`, `LockableInterface`, `StatsProviderInterface`

Stores cache entries in a Memcached cluster using the `ext-memcached` PHP extension. Because Memcached does not natively support key enumeration or prefix-based deletion, the adapter maintains a per-prefix index key that tracks all written keys.

### Requirements

- The `ext-memcached` PHP extension must be installed.
- A `Memcached` instance with at least one server added; connection management is the caller's responsibility.

### Constructor

```php
public function __construct (
    Memcached $memcached,
    string $prefix = "wingman_cache:",
    int $defaultTtl = 0
)
```

| Parameter | Description |
|---|---|
| `$memcached` | A `Memcached` instance with servers already configured. |
| `$prefix` | Key prefix; also used as the name of the index key tracking all written keys. |
| `$defaultTtl` | Fallback TTL in seconds for write operations where TTL = 0. |

### Key-Enumeration Index

Memcached has no server-side key enumeration. To support `list()` and tag-driven deletion, the adapter maintains a side-channel index stored under `"{$prefix}_index"`. Each write adds the key to the index; each delete removes it. The index itself is stored with no TTL to ensure it outlives all entries it tracks.

### Locking

Locks are implemented with `Memcached::add()`, which is atomic and only succeeds when the key does not already exist. Lock expiry is set via the standard Memcached item TTL.

### Limitations

- No native atomic counter support (`CounterInterface` is not implemented). `increment()` and `decrement()` fall back to a read-modify-write cycle in `Cacher`.
- Key-enumeration index can drift if keys expire naturally in Memcached without a corresponding `delete()` call. Run `synchroniseIndices()` periodically to reconcile.

### Example

```php
use Memcached;
use Wingman\Stasis\Adapters\MemcachedAdapter;
use Wingman\Stasis\Cacher;

$memcached = new Memcached();
$memcached->addServer("127.0.0.1", 11211);

$cacher = new Cacher();
$cacher->setAdapter(new MemcachedAdapter($memcached, prefix: "myapp:"));

$cacher->set("product.99", $product, ttl: 7200);
```

---

## Writing a Custom Adapter

Implement `AdapterInterface` to add a new storage backend. The interface requires six methods:

```php
use Wingman\Stasis\Interfaces\AdapterInterface;

class MyAdapter implements AdapterInterface {
    public function append (string $path, string $contents) : bool { /* ... */ }
    public function delete (string $path) : bool { /* ... */ }
    public function exists (string $path) : bool { /* ... */ }
    public function list (string $path) : array { /* ... */ }
    public function read (string $path) : string { /* ... */ }
    public function write (string $path, string $contents) : bool { /* ... */ }
}
```

Optionally implement any combination of the capability interfaces:

| Interface | Benefit |
|---|---|
| `CounterInterface` | Native atomic counters for `increment()` / `decrement()`. |
| `LockableInterface` | Stampede protection in `remember()`. |
| `PrunableInterface` | Empty-directory sweep in `collectGarbage()`. |
| `StatsProviderInterface` | Stats returned by `getStats()`. |

Register the adapter:

```php
$cacher->setAdapter(new MyAdapter());
```

---

*Further reading:* [Overview](overview.md) · [Configuration](configuration.md) · [API Reference](api-reference.md)
