# Tagging

Tags provide a mechanism for grouping cache entries so they can be invalidated as a unit. A single cache entry can carry multiple tags; a single tag can be associated with any number of entries. Tag-based invalidation is always by value: clearing a tag removes entries, not the tag file itself.

---

## Table of Contents

- [Tagging](#tagging)
  - [Table of Contents](#table-of-contents)
  - [Assigning Tags](#assigning-tags)
  - [Invalidating by Tag](#invalidating-by-tag)
  - [Listing Items by Tag](#listing-items-by-tag)
  - [Tag Index Files](#tag-index-files)
  - [Maximum-Performance Mode](#maximum-performance-mode)
  - [Synchronising Indices](#synchronising-indices)
  - [Rebuilding Tag Indices](#rebuilding-tag-indices)

---

## Assigning Tags

Pass an array of tag strings to any write method:

```php
// set
$cacher->set("product.42", $product, ttl: 3600, tags: ["products", "catalogue"]);

// setMultiple
$cacher->setMultiple([
    "product.1" => $product1,
    "product.2" => $product2
], ttl: 3600, tags: ["products"]);

// remember
$featured = $cacher->remember(
    "products.featured",
    fn() => $db->getFeaturedProducts(),
    ttl: 900,
    tags: ["products", "homepage"]
);
```

Tags are stored in the `Cache` value object and reflected in the corresponding tag index files under `<root>/tags/`.

---

## Invalidating by Tag

`Cacher::clearByTags()` accepts a single tag string or an array of tags, deletes every matching cache file, removes their entries from the registry, and updates (or removes) the affected index files.

```php
// Invalidate everything tagged "products"
$removed = $cacher->clearByTags("products");

// Invalidate multiple tags at once
$removed = $cacher->clearByTags(["products", "homepage"]);

echo "Removed $removed cache entries.";
```

The return value is the total number of entries deleted across all specified tags.

---

## Listing Items by Tag

`Cacher::getItemsByTag()` returns an iterable of `Cache` objects for all non-expired entries carrying the given tag:

```php
foreach ($cacher->getItemsByTag("products") as $cache) {
    echo $cache->getLocation() . " — TTL: " . $cache->getTTL() . "\n";
}
```

Expired entries encountered during iteration are skipped but not deleted. Call `collectGarbage()` or `synchroniseIndices()` afterwards to remove stale paths from the index.

---

## Tag Index Files

Each tag is backed by a plain-text index file stored under `<root>/<tagDirectory>/`:

```
temp/cache/
└── tags/
    ├── products.idx
    └── homepage.idx
```

The content of each `.idx` file is a newline-delimited list of cache-file paths relative to `<root>`:

```
2f/3a/2f3a8c...cache
4b/1e/4b1e9d...cache
```

Index files are created automatically on first write and removed automatically when the last entry is deleted.

The extension (`idx` by default) and directory name (`tags` by default) are configurable:

```php
// config/cacher.php
"tagDirectory"   => "tag-indices",
"indexExtension" => "index"
```

---

## Maximum-Performance Mode

By default, `TagManager::registerTags()` reads the existing index file before writing to avoid duplicating paths. This is safe but incurs one extra read I/O per tagged write.

When `cacher.maxPerformanceMode` is `true`, the read step is skipped and paths are appended unconditionally. This halves the I/O cost of tagging, but the index files may accumulate duplicate entries over time.

**When to use maximum-performance mode:**

- High write throughput where tagged entries are created at a very high rate.
- Applications that already run `synchroniseIndices()` on a schedule (e.g. in a maintenance task or the `cache:repair` console command).

**When to avoid it:**

- Applications that call `getItemsByTag()` frequently with large tag sets; the duplicates cause redundant file reads.
- Applications that never run maintenance tasks.

Enable it in configuration:

```php
"maxPerformanceMode" => true
```

---

## Synchronising Indices

`TagManager::synchroniseIndices()` removes stale paths from all tag index files. A path is considered stale if:

1. It no longer exists on the filesystem (the cache file was deleted).
2. It appears in the optional `$knownDeleted` array (freshly deleted paths can be passed in directly to avoid an `exists()` call).

```php
$removed = $cacher->getTagManager()->synchroniseIndices();
echo "Removed {$removed} stale index entries.";
```

This is the reconciliation step that compensates for maximum-performance mode and any out-of-band cache file deletions.

---

## Rebuilding Tag Indices

When index files become severely degraded (e.g. after a crash mid-write or a manual deletion of cache files), use `rebuildTagIndices()` to regenerate all index files from scratch:

```php
$entries = $cacher->getTagManager()->rebuildTagIndices();
echo "Rebuilt tag indices with {$entries} total entries.";
```

The rebuild process:

1. Deletes all existing tag index files.
2. Scans every `.cache` file in the cache tree.
3. Reads the `tags` array from each `Cache` object.
4. Writes fresh index files for each discovered tag.

This is equivalent to the `--rebuild-tags` flag of the `cache:repair` command. See [Console](console.md) for automated maintenance.

---

*Further reading:* [Garbage Collection](garbage-collection.md) · [Configuration](configuration.md) · [Console](console.md) · [API Reference](api-reference.md)
