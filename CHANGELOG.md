# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

You can find and compare releases at the [GitHub release page](https://github.com/angelpolitis/Wingman-Stasis/releases).

---

## [1.0.0] — 2026-03-20

### Added

- **`Cacher`** — Core caching engine; manages cache items via a swappable adapter, with key sharding, configurable TTLs, tag tracking, and a built-in expiry registry.
- **`Cache`** — Immutable value object representing a stored cache entry; carries content, tags, metadata, TTL, and creation date. Implements `JsonSerializable` and uses a double-serialisation strategy in `__serialize()` / `__unserialize()` to allow safely restricting `unserialize()` to `Cache::class` while still supporting arbitrary content types.
- **`TagManager`** — Manages tag index files; supports two write modes (standard read-check-append and maximum-performance blind-append), in-memory deduplication buffer, `rebuildTagIndices()`, `synchroniseIndices()`, and `registerTags()`.
- **`PathUtils`** — Static utility class for path normalisation; canonical separator fixing, trailing-separator enforcement, relative-path calculation, fragment joining, and a `resolvePath()` that prevents path traversal outside a specified root.
- **Four adapters:**
  - `LocalAdapter` — Filesystem-backed; implements `AdapterInterface`, `LockableInterface`, `PrunableInterface`. Locking via `flock(LOCK_EX | LOCK_NB)` on a sidecar `.lock` file.
  - `ApcuAdapter` — APCu shared-memory backend; implements `AdapterInterface`, `CounterInterface`, `LockableInterface`, `StatsProviderInterface`. Atomic counters via `apcu_inc()` / `apcu_dec()`; atomic locking via `apcu_add()`.
  - `RedisAdapter` — Redis backend; implements `AdapterInterface`, `CounterInterface`, `LockableInterface`, `StatsProviderInterface`. Atomic counters via `INCRBY` / `DECRBY`; atomic locking via `SET key value NX PX ttl`; non-blocking `SCAN`-based key enumeration.
  - `MemcachedAdapter` — Memcached cluster backend; implements `AdapterInterface`, `LockableInterface`, `StatsProviderInterface`. Flat key namespace emulated with per-prefix index keys; atomic locking via `Memcached::add()`.
- **Five capability interfaces:**
  - `AdapterInterface` — Core storage contract (`append`, `delete`, `exists`, `list`, `read`, `write`).
  - `CounterInterface` — Native atomic counter operations (`adjustCounter`).
  - `LockableInterface` — Exclusive lock acquisition and release (`acquireLock`, `releaseLock`).
  - `PrunableInterface` — Recursive empty-directory pruning (`pruneEmptyDirectories`).
  - `StatsProviderInterface` — Storage-level statistics (`getStats`).
- **Six typed exceptions**, all extending SPL base types and implementing the `Exception` marker interface: `InvalidAdapterException`, `InvalidShardConfigException`, `MissingDependencyException`, `NonNumericValueException`, `PathEscapeException`, `StorageException`.
- **Stampede protection** in `Cacher::remember()` — try-lock / double-check / release pattern with configurable spin-wait budget; used automatically when the active adapter implements `LockableInterface`.
- **PSR-16-compatible method signatures** — `get`, `set`, `delete`, `has`, `getMultiple`, `setMultiple`, `deleteMultiple` all follow PSR-16 conventions; `DateInterval` TTL support throughout via `resolveTtl()`.
- **Expiry registry** — `registry.idx` tracks path-to-expiry-timestamp pairs; drives efficient `collectGarbage()` without scanning every cache file. Rebuilt on demand via `rebuildRegistry()`.
- **Bridge: Cortex** — `Bridge\Cortex\Configuration` and `Bridge\Cortex\Attributes\Configurable`: file-level guard + `class_alias` when Cortex is present; no-op stubs otherwise. Enables `#[Configurable]` attribute-driven property hydration without a mandatory Cortex dependency.
- **Bridge: Synapse** — `Bridge\Synapse\Provider` (registers `Cacher` as a singleton, resolves adapter and configuration from the container) and `Bridge\Synapse\CacherFactory` (named-store pool keyed by configuration under `cacher.stores.<name>`).
- **Bridge: Console** — `Bridge\Console\Commands\RepairCommand` implements `Benchmarkable`; runs `rebuildRegistry()`, `rebuildTagIndices()`, and `collectGarbage()` with a styled summary table and deep benchmark metrics.
