# Configuration

Stasis can be configured in two ways: by passing constructor arguments to `Cacher` directly, or — when the Cortex module is installed — by placing values in the application configuration file and letting the `#[Configurable]` attribute hydrate the class automatically.

---

## Table of Contents

- [Configuration](#configuration)
  - [Table of Contents](#table-of-contents)
  - [Without Cortex](#without-cortex)
  - [With Cortex](#with-cortex)
  - [Cacher Keys](#cacher-keys)
  - [TagManager Keys](#tagmanager-keys)
  - [Named Stores](#named-stores)

---

## Without Cortex

Pass values directly to the `Cacher` constructor. Only root, permission, hashing algorithm, and adapter can be set this way; TTL, sharding, and extension options require Cortex or a manual `Configuration` object.

```php
use Wingman\Stasis\Cacher;
use Wingman\Stasis\Adapters\RedisAdapter;

$cacher = new Cacher(
    root: "storage/cache",
    permission: 0775,
    hashingAlgorithm: "md5"
);

$cacher->setAdapter(new RedisAdapter($redis));
```

---

## With Cortex

When Cortex is installed, add a `cacher` block to your application configuration file. Every key under `cacher` maps directly to a `Cacher` or `TagManager` property via the `#[Configurable("cacher.*")]` attribute.

```php
// config/cacher.php
return [
    "root" => env("CACHE_DIR", "temp/cache"),
    "permission" => 0755,
    "hashingAlgorithm" => "sha256",
    "ttl" => 86400,
    "strict" => false,
    "shardingEnabled" => true,
    "shardDepth" => 2,
    "shardLength" => 2,
    "cacheExtension" => "cache",
    "minShardLength" => 1,
    "maxShardLength" => 8,
    "loggingEnabled" => false,
    "registryFile" => "registry.idx",
    "tagDirectory" => "tags",
    "indexExtension" => "idx",
    "maxPerformanceMode" => false
];
```

Cortex hydrates each annotated property before any explicit constructor argument is applied. Constructor arguments always win over configuration values.

---

## Cacher Keys

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `cacher.root` | `string` | `"temp/cache"` | Relative path from the project root to the cache directory. Overridden by the `CACHE_DIR` environment variable. |
| `cacher.permission` | `int` | `0755` | Octal permission mode used when creating new cache directories. |
| `cacher.hashingAlgorithm` | `string` | `"sha256"` | Algorithm passed to `hash()` for key fingerprinting. Any algorithm supported by `hash_algos()` is accepted. |
| `cacher.ttl` | `int` | `86400` | Default TTL in seconds. Applied when `null` is passed as the TTL argument to `set()`, `remember()`, or `increment()`. A value of `0` means never expire. |
| `cacher.strict` | `bool` | `false` | When `true`, reading a key whose stored file passes path validation but whose `Cache` object fails deserialisation throws `StorageException` instead of returning the default value. |
| `cacher.shardingEnabled` | `bool` | `true` | Whether to distribute cache files across a sharded directory tree. Disabling this places all files directly in the root. |
| `cacher.shardDepth` | `int` | `2` | Number of directory levels in the shard tree. Must satisfy `shardDepth × shardLength ≤ hash_length`. |
| `cacher.shardLength` | `int` | `2` | Number of hash characters used per shard level. |
| `cacher.minShardLength` | `int` | `1` | Minimum accepted value for `shardLength`. Validated at construction. |
| `cacher.maxShardLength` | `int` | `8` | Maximum accepted value for `shardLength`. Validated at construction. |
| `cacher.cacheExtension` | `string` | `"cache"` | File extension appended to every cache file (without leading dot). |
| `cacher.loggingEnabled` | `bool` | `false` | When `true`, cache hits, misses, and errors are passed to the active logger (requires Cortex). |
| `cacher.registryFile` | `string` | `"registry.idx"` | Filename of the expiry registry, relative to `cacher.root`. |

---

## TagManager Keys

The following keys are hydrated into `TagManager` (also under the `cacher.*` prefix).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `cacher.tagDirectory` | `string` | `"tags"` | Name of the subdirectory under `cacher.root` that holds tag index files. |
| `cacher.indexExtension` | `string` | `"idx"` | File extension used for tag index files (without leading dot). |
| `cacher.maxPerformanceMode` | `bool` | `false` | When `true`, `registerTags()` skips the read-before-append deduplication check, reducing I/O at the cost of requiring periodic `synchroniseIndices()` calls to clean up duplicate entries. |

---

## Named Stores

When the Synapse bridge is active, multiple `Cacher` instances with different configurations can be registered as named stores. Each named store lives under `cacher.stores.<name>` and inherits any key not explicitly overridden from the root `cacher` block.

```php
// config/cacher.php
return [
    "root" => "temp/cache",
    "ttl" => 86400,
    "stores" => [
        "sessions" => [
            "root" => "temp/sessions",
            "ttl" => 1800
        ],
        "api" => [
            "root" => "temp/api-cache",
            "hashingAlgorithm" => "md5"
        ]
    ]
];
```

Retrieve a named store via the factory:

```php
/** @var \Wingman\Stasis\Bridge\Synapse\CacherFactory $factory */
$sessions = $factory->store("sessions");
$api = $factory->store("api");
```

See [Bridges](bridges.md#synapse) for how `CacherFactory` is registered in the container.

---

*Further reading:* [Overview](overview.md) · [Adapters](adapters.md) · [Bridges](bridges.md) · [API Reference](api-reference.md)
