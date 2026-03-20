# Console

The `cache:repair` command performs a full maintenance cycle on the cache store: it rebuilds the expiry registry, rebuilds all tag indices, runs garbage collection, and outputs a styled benchmark summary.

It is part of the [Console bridge](bridges.md#console-bridge) and requires the Wingman Console module.

---

## Table of Contents

- [Console](#console)
  - [Table of Contents](#table-of-contents)
  - [Registration](#registration)
  - [Usage](#usage)
  - [Flags](#flags)
  - [Output](#output)
  - [`Benchmarkable` Interface](#benchmarkable-interface)

---

## Registration

Add the command to your application's command list:

```php
// config/console.php
"commands" => [
    \Wingman\Stasis\Bridge\Console\Commands\RepairCommand::class
]
```

The command is resolved from the Synapse container; it expects a `Cacher` singleton to be registered (provided automatically by `Bridge\Synapse\Provider`).

---

## Usage

```bash
wingman cache:repair
```

Run a full repair cycle:

1. Rebuild the expiry registry (`rebuildRegistry()`).
2. Rebuild all tag index files (`rebuildTagIndices()`).
3. Run garbage collection (`collectGarbage()`).

All three operations are benchmarked and their results are displayed in a summary table.

---

## Flags

| Flag | Description |
| --- | --- |
| `--registry` | Run only the registry rebuild step, skipping tag rebuild and garbage collection. |
| `--tags` | Run only the tag index rebuild step, skipping registry rebuild and garbage collection. |
| `--gc` | Run only the garbage collection step, skipping registry and tag rebuilds. |
| `--quiet` | Suppress all output; exit code still reflects success or failure. |

When no flags are passed, all three steps are executed. Flags may be combined:

```bash
# Rebuild registry and run GC, but skip tag rebuild
wingman cache:repair --registry --gc
```

---

## Output

A successful run produces output similar to the following:

```
Stasis · Cache Repair

 ┌────────────────────────────┬────────────┬──────────────┐
 │ Operation                  │ Result     │ Duration     │
 ├────────────────────────────┼────────────┼──────────────┤
 │ Registry rebuild           │ 243 entries│    12.4 ms   │
 │ Tag index rebuild          │ 87 entries │     8.1 ms   │
 │ Garbage collection         │ 18 deleted │     3.2 ms   │
 │   ↳ Bytes freed            │ 42.1 KB    │              │
 │   ↳ Directories pruned     │ 6          │              │
 │   ↳ Registry remaining     │ 225        │              │
 └────────────────────────────┴────────────┴──────────────┘

 Total time: 23.7 ms  ·  Peak memory: 1.4 MB
```

The command exits with code `0` on success and `1` if any step throws an exception.

---

## `Benchmarkable` Interface

`RepairCommand` implements `Wingman\Console\Interfaces\Benchmarkable`. This interface instructs the Wingman Console runner to:

- Wrap the `run()` call in a high-resolution timer (`hrtime(true)`).
- Track peak memory usage across the command's lifetime.
- Inject both metrics into the summary table automatically.

No additional code is required in the command itself; the timing and memory values appear in the output purely because the interface is declared.

---

*Further reading:* [Garbage Collection](garbage-collection.md) · [Tagging](tagging.md) · [Bridges](bridges.md)
