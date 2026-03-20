# Garbage Collection

As cache entries expire they are not removed automatically; Stasis uses a *collect-on-demand* model. This keeps the write path fast and predictable, at the cost of requiring explicit maintenance to reclaim disk space.

Three complementary operations handle maintenance:

| Operation | What it does |
| --- | --- |
| `collectGarbage()` | Reads the registry, deletes expired files, purges the registry entries, prunes empty directories. |
| `rebuildRegistry()` | Rebuilds `registry.idx` from scratch by scanning all cache files. |
| `pruneEmptyDirectories()` | Removes empty directories left behind after deletions. |

All three are exposed through the `Cacher` public API and are also invoked together by the [`cache:repair` console command](console.md).

---

## Table of Contents

- [Garbage Collection](#garbage-collection)
  - [Table of Contents](#table-of-contents)
  - [The Registry](#the-registry)
  - [`collectGarbage()`](#collectgarbage)
  - [`rebuildRegistry()`](#rebuildregistry)
  - [`pruneEmptyDirectories()`](#pruneemptydirectories)
  - [Scheduling Maintenance](#scheduling-maintenance)
    - [Manually in code](#manually-in-code)
    - [On a periodic schedule (CRON example)](#on-a-periodic-schedule-cron-example)
    - [Via the Wingman console](#via-the-wingman-console)
    - [After bulk invalidations](#after-bulk-invalidations)

---

## The Registry

The registry is a tab-delimited flat file (`registry.idx` by default) stored at the root of the cache directory. Each line records the relative path and the Unix expiry timestamp of one cache entry:

```
2f/3a/2f3a8c...cache	1748700000
4b/1e/4b1e9d...cache	0
9c/7f/9c7fe1...cache	1748796000
```

An expiry of `0` marks a never-expiring entry. The registry is updated on every write and every delete, keeping it consistent without a full scan. If the registry becomes inconsistent (e.g. after a crash or manual file removal), `rebuildRegistry()` regenerates it.

---

## `collectGarbage()`

Reads the registry line by line, checks each entry's expiry timestamp against the current time, and removes every expired cache file. Registry entries for deleted files are removed. If the adapter implements `PrunableInterface`, empty directories are swept afterwards.

```php
$result = $cacher->collectGarbage();

echo "Deleted: {$result['deleted']} files\n";
echo "Freed: {$result['freed']} bytes\n";
echo "Pruned: {$result['pruned']} directories\n";
echo "Remaining: {$result['remaining']} entries\n";
```

The returned array always contains:

| Key | Type | Description |
| --- | --- | --- |
| `deleted` | `int` | Number of cache files removed. |
| `freed` | `int` | Total bytes freed. |
| `pruned` | `int` | Empty directories removed (`0` when pruning is not available). |
| `remaining` | `int` | Registry entries surviving after collection. |

`collectGarbage()` does not touch non-expiring entries (`TTL = 0`).

---

## `rebuildRegistry()`

When the registry is missing, corrupted, or out of sync with the actual file system, call `rebuildRegistry()` to recreate it from scratch.

```php
$count = $cacher->rebuildRegistry();
echo "Registry rebuilt with {$count} entries.";
```

The process:

1. Reads every `.cache` file under the cache root.
2. Deserialises each `Cache` object to extract the TTL and creation date.
3. Computes the expiry timestamp from `creationDate + ttl`.
4. Writes a fresh `registry.idx`.

Because this involves reading every cache file, it is an `O(n)` operation and should only be run as a maintenance task, not in the hot path.

---

## `pruneEmptyDirectories()`

After many deletions, sharded cache trees often contain chains of empty directories. `pruneEmptyDirectories()` removes them recursively, starting from the given directory (default: cache root).

```php
$pruned = $cacher->pruneEmptyDirectories();
echo "Pruned {$pruned} empty directories.";
```

This method delegates to `PrunableInterface::pruneEmptyDirectories()` on the active adapter. It is a no-op when the adapter does not implement `PrunableInterface` (e.g. `RedisAdapter` or `ApcuAdapter`).

The following directories are never removed even if empty:

- The `tags/` directory (tag index files are transient and the directory must persist).
- Any path listed in the adapter's internal exclusion list.

---

## Scheduling Maintenance

Maintenance tasks can be run:

### Manually in code

```php
// After a batch write or a large clearByTags call:
$cacher->collectGarbage();
```

### On a periodic schedule (CRON example)

```bash
# Run garbage collection every hour
0 * * * * wingman cache:repair
```

### Via the Wingman console

When the Console bridge is active, the `cache:repair` command runs all three operations in sequence with a styled benchmark table:

```bash
wingman cache:repair
```

See [Console](console.md) for all available flags and output format.

### After bulk invalidations

Tag-based invalidation (`clearByTags()`) removes cache files and their registry entries but does not prune empty directories. Follow a bulk invalidation with:

```php
$cacher->pruneEmptyDirectories();
$cacher->getTagManager()->synchroniseIndices();
```

Or simply run `cache:repair` to handle everything at once.

---

*Further reading:* [Tagging](tagging.md) · [Console](console.md) · [Configuration](configuration.md) · [API Reference](api-reference.md)
