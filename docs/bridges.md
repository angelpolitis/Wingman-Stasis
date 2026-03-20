# Bridges

Bridges let Stasis integrate with other Wingman modules — Cortex for configuration, Synapse for dependency injection, and Wingman Console for maintenance commands — without making any of those modules a mandatory dependency.

Each bridge file uses a *file-level guard* pattern: it checks whether the real class is already available, aliases the stub to the real class if so, and otherwise defines its own no-op (or minimal) stub. This means Stasis's core classes safely import from the bridge namespace at all times.

---

## Table of Contents

- [How Bridges Work](#how-bridges-work)
- [Cortex Bridge](#cortex-bridge)
- [Synapse Bridge](#synapse-bridge)
- [Console Bridge](#console-bridge)

---

## How Bridges Work

Every bridge file follows this pattern at the top:

```php
namespace Wingman\Stasis\Bridge\Cortex;

// If the real Cortex class exists, alias it into this namespace and stop.
if (class_exists(\Wingman\Cortex\Configuration::class)) {
    class_alias(\Wingman\Cortex\Configuration::class, Configuration::class);
    return;
}

// Otherwise, define a minimal stub.
class Configuration {
    public static function find (string $key, mixed $default = null): mixed { ... }
    public static function hydrate (object $target, mixed $config): void { }
    public static function get (string $key, mixed $default = null): mixed { ... }
}
```

**Key details:**

- `class_exists()` does not trigger autoloading, so this guard never causes a fatal error when the real module is absent.
- `class_alias()` maps the real fully-qualified class name to the bridge name. After this call, `new \Wingman\Stasis\Bridge\Cortex\Configuration` is indistinguishable from `new \Wingman\Cortex\Configuration`.
- The `return` after `class_alias()` prevents the stub class body from being parsed, avoiding a "cannot redeclare class" error.
- Stasis's source files always import from `Wingman\Stasis\Bridge\Cortex\*`; they never import directly from `Wingman\Cortex\*`.

---

## Cortex Bridge

**Namespace:** `Wingman\Stasis\Bridge\Cortex` **Files:** `src/Bridge/Cortex/Configuration.php`, `src/Bridge/Cortex/Attributes/Configurable.php`

Provides two stubs:

### `Configuration`

A no-op stand-in for `Wingman\Cortex\Configuration`. When Cortex is absent, all methods return `null` or perform no action:

| Method | Stub behaviour |
|---|---|
| `Configuration::find(string $key, mixed $default = null)` | Returns `$default`. |
| `Configuration::hydrate(object $target, mixed $config)` | No-op. |
| `Configuration::get(string $key, mixed $default = null)` | Returns `$default`. |

When Cortex is present, the real `Wingman\Cortex\Configuration` class is aliased in its place, providing full configuration resolution.

### `Configurable`

A `#[Attribute]` stub for `Wingman\Cortex\Attributes\Configurable`. When Cortex is absent, the attribute is legally declared but has no effect at runtime. When Cortex is present, the real attribute is aliased and Cortex hydrates annotated properties automatically.

```php
#[Configurable("cacher.root")]
private string $root = "temp/cache";
```

### Requiring Cortex

To make Cortex a hard dependency in your application:

```json
// composer.json
{
    "require": {
        "wingman/stasis": "^1.0",
        "wingman/cortex": "^1.0"
    }
}
```

No code changes are needed; Stasis will use the real `Wingman\Cortex\Configuration` automatically once it is available.

---

## Synapse Bridge

**Namespace:** `Wingman\Stasis\Bridge\Synapse` **Files:** `src/Bridge/Synapse/Provider.php`, `src/Bridge/Synapse/CacherFactory.php`

Registers Stasis with the Synapse dependency-injection container.

### `Provider`

Extends `Wingman\Synapse\Provider`. Add it to your application's provider list:

```php
// config/app.php  (or equivalent Synapse bootstrap)
"providers" => [
    \Wingman\Stasis\Bridge\Synapse\Provider::class,
]
```

The provider performs two actions:

**`register()`** — Binds `Cacher` as a lazily-resolved singleton. On first resolution:

1. The `Configuration` is retrieved from the container under the key `\Wingman\Cortex\Configuration::class`.
2. An adapter is sought in the container; if absent, `Cacher` is constructed without one (it will fall back to `LocalAdapter`).
3. A `Cacher` instance is returned and cached for the lifetime of the container.

**`boot()`** — Registers `CacherFactory` as a singleton in the container once all providers have been registered.

### `CacherFactory`

Resolves named Cacher stores from the application configuration.

```php
public function __construct (
    Container $container,
    Configuration $configuration
)
```

#### `store(?string $name = null) : Cacher`

Returns the `Cacher` for the named store defined under `cacher.stores.<name>`. When `$name` is `null`, the default `Cacher` singleton is returned from the container.

```php
/** @var \Wingman\Stasis\Bridge\Synapse\CacherFactory $factory */
$default = $factory->store();             // default Cacher singleton
$sessions = $factory->store("sessions");  // cacher.stores.sessions
$api = $factory->store("api");            // cacher.stores.api
```

Named stores share the same class-level defaults but use independent configuration scopes, so they can target different root directories, TTLs, or adapters.

---

## Console Bridge

**Namespace:** `Wingman\Stasis\Bridge\Console\Commands` **Optional dependency:** `Wingman/Console`

Provides the `cache:repair` CLI command for automated maintenance. The command is auto-discovered by the Wingman Console's `Registry` at runtime by scanning `src/Bridge/Console/Commands/*.php` across all sibling modules and reading the `#[Command(...)]` attribute — no manual registration is required.

The bridge has no effect on caching itself and is harmlessly absent when Wingman Console is not installed.

See [Console](console.md) for full command documentation.

---

*Further reading:* [Configuration](configuration.md) · [Console](console.md) · [Adapters](adapters.md)
