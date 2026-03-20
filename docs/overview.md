# Overview

Stasis is a filesystem-first caching library for the Wingman framework. It provides a single `Cacher` class backed by a hot-swappable adapter, an optional tagging layer, PSR-16-compatible multi-get/set/delete operations, native atomic counters, and stampede protection — with zero mandatory dependencies beyond PHP 8.0.

---

## Table of Contents

- [Overview](#overview)
  - [Table of Contents](#table-of-contents)
  - [Core Concepts](#core-concepts)
    - [Cache Item (`Cache`)](#cache-item-cache)
    - [Adapter](#adapter)
    - [Registry](#registry)
    - [Tags](#tags)
  - [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Sharding](#sharding)
  - [TTL Semantics](#ttl-semantics)
  - [Adapter Capabilities](#adapter-capabilities)

---

## Core Concepts

### Cache Item (`Cache`)

Every stored value is wrapped in a `Cache` value object. The object carries:

- **Content** — the original PHP value, serialised to the adapter's storage format.
- **Tags** — zero or more arbitrary string labels used for grouped invalidation.
- **Metadata** — an open key/value array for application-level annotations.
- **TTL** — the lifetime in seconds. `0` means *never expire*.
- **Creation date** — a `DateTimeImmutable` recorded at write time; combined with TTL to derive the expiry timestamp without storing it separately.

`Cache` implements `JsonSerializable` and uses a double-serialisation strategy in its `__serialize()` / `__unserialize()` methods so that `unserialize()` can be safely restricted to `allowed_classes: [Cache::class]` even when the *content* is an arbitrary object.

### Adapter

An adapter is any class that implements `AdapterInterface`. The adapter owns the physical read, write, delete, and list operations; `Cacher` owns all higher-level logic (sharding, TTL, tagging, stampede protection, garbage collection).

Adapters are loosely coupled to `Cacher` through a narrow, six-method interface, making it straightforward to target a different storage backend without touching application code.

### Registry

The registry (`registry.idx` by default) is a newline-delimited flat file stored at the root of the cache directory. Each line contains a relative path and an expiry timestamp separated by a tab character:

```
2f/3a/2f3a8c...cache    1748700000
4b/1e/4b1e9d...cache    0
```

An expiry of `0` indicates a non-expiring entry. The registry drives `collectGarbage()`, eliminating the need to stat every file in the tree.

### Tags

Tag index files live under a dedicated subdirectory (default: `tags/`). Each tag has its own `.idx` file listing the relative paths of every cache entry carrying that tag. Lookups are `O(n)` in the number of tagged items, not in the total cache size.

---

## Architecture

```
Application
    │
    ▼
 Cacher ───────────────────────────► TagManager
    │                                    │
    │  read / write / delete / list      │  register / synchronise / rebuild
    ▼                                    ▼
 AdapterInterface               tags/<tag>.idx files
    │
    ├── LocalAdapter    (filesystem)
    ├── ApcuAdapter     (APCu shared memory)
    ├── RedisAdapter    (Redis)
    └── MemcachedAdapter (Memcached cluster)
```

`Cacher` delegates all physical I/O to the active adapter. `TagManager` is constructed lazily on first use and operates exclusively through `Cacher`'s own public API, keeping the tag layer fully decoupled from adapter internals.

---

## Directory Structure

A typical filesystem cache tree looks like this:

```
temp/cache/
├── registry.idx          ← expiry registry (path → timestamp)
├── tags/
│   ├── products.idx      ← newline list of paths tagged "products"
│   └── homepage.idx
└── 2f/                   ← shard level 1 (first 2 hex chars of hash)
    └── 3a/               ← shard level 2 (next 2 hex chars)
        └── 2f3a8c....cache
```

The structure is transparent to application code; `Cacher::createPathFromKey()` handles path derivation from a string cache key and the active configuration.

---

## Sharding

Sharding distributes cache files across nested subdirectories, preventing filesystem degradation when the number of cached items is large.

| Parameter | Config key | Default |
|---|---|---|
| Enabled | `cacher.shardingEnabled` | `true` |
| Depth (directory levels) | `cacher.shardDepth` | `2` |
| Length (characters per level) | `cacher.shardLength` | `2` |

With the default settings, the 64-character SHA-256 fingerprint of a key is split at positions `[0..1]` and `[2..3]`, yielding two directory components:

```
key = "user.42.profile"
hash = "2f3a8c..."
path = 2f/3a/2f3a8c...cache
```

Adjusting `shardDepth` and `shardLength` affects only new writes; existing entries remain valid because their full hash is preserved in the filename. `InvalidShardConfigException` is thrown at construction if `shardLength × shardDepth` would exceed the available hash length for the chosen `hashingAlgorithm`.

---

## TTL Semantics

TTLs are expressed in seconds. `DateInterval` objects are also accepted wherever a TTL parameter appears; they are normalised to seconds internally.

| Value | Meaning |
|---|---|
| `> 0` | Expires after the given number of seconds. |
| `0` | Never expires. |
| `null` | Falls back to `cacher.ttl` (default: `86400`). |

The `Cache` value object exposes `isExpired()`, `isFresh()`, and `getRemainingTime()` for inspecting expiry state without calling back into `Cacher`.

---

## Adapter Capabilities

Not all adapters support every feature. The table below summarises which capability interfaces each adapter implements.

| Adapter | Atomic counters | Locking | Pruning | Stats |
|---|:---:|:---:|:---:|:---:|
| `LocalAdapter` | — | ✓ | ✓ | — |
| `ApcuAdapter` | ✓ | ✓ | — | ✓ |
| `RedisAdapter` | ✓ | ✓ | — | ✓ |
| `MemcachedAdapter` | — | ✓ | — | ✓ |

- **Stampede protection** in `Cacher::remember()` is activated only when the active adapter implements `LockableInterface`.
- **`increment()` / `decrement()`** delegate to `CounterInterface::adjustCounter()` when available; otherwise they fall back to a read-modify-write cycle.
- **`collectGarbage()`** calls `PrunableInterface::pruneEmptyDirectories()` when available to clean up the directory tree after deleting expired entries.
- **`getStats()`** forwards to `StatsProviderInterface::getStats()` when the adapter implements it; for adapters that do not, a no-op array is returned.

---

*Further reading:* [Adapters](adapters.md) · [Configuration](configuration.md) · [Tagging](tagging.md) · [API Reference](api-reference.md)
