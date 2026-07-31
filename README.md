# Shared Data Extension for PHP

[![CI](https://github.com/lisachenko/php-shared-data-extension/actions/workflows/ci.yml/badge.svg)](https://github.com/lisachenko/php-shared-data-extension/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/lisachenko/php-shared-data-extension?include_prereleases)](https://packagist.org/packages/lisachenko/php-shared-data-extension)
[![PHP 8.4](https://img.shields.io/badge/php-8.4-777BB3.svg?logo=php&logoColor=white)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**PHP objects that survive the request boundary. In pure PHP.**

Every PHP request pays the same tax: rebuild the framework kernel, reboot the DI
container, reparse the configuration — throw it all away, repeat. This library
deletes that tax. Persist an object **once per worker** and get the very same,
fully-initialized instance back on every subsequent request — no serialization,
no cache round-trip, no C extension to compile. Just `composer require` and FFI.

```php
use Lisachenko\SharedData\PersistentStore;
use ZEngine\Core;

Core::init(); // or Core::preload() from opcache.preload

$store = PersistentStore::boot();

if (!$store->has(AppConfig::class)) {
    // Expensive one-time initialization: runs ONCE per worker process
    $config = $store->persist(AppConfig::class, buildExpensiveConfig());
} else {
    // Every later request in this worker: instant recovery, zero rebuild cost
    $config = $store->get(AppConfig::class);
}
```

## Why you'll like it

- ⚡ **Zero rebuild cost** — kernels, containers, config and route tables are
  built once per worker and reattached in microseconds on every request.
- 🧠 **Real objects, not copies** — the state lives in the engine's own
  persistent memory, the same trick PHP uses for interned strings; reads are
  zero-copy and mutations copy-on-write into request memory.
- 🕸 **Whole object graphs** — persist a root and everything it references comes
  along: nested objects, objects inside arrays, shared sub-objects (persisted
  once, identity preserved) and cycles.
- 🔗 **Shared across graphs** — a persisted object can join a second graph by
  reference: `$a->child === $b->left` holds inside a request and across them.
- 🧹 **Droppable** — `drop(ClassName::class)` removes an entry and hands the
  memory of everything no other entry still references back to the process.
- 🎯 **Typed API** — storage is keyed by `ClassName::class` with PHPStan generic
  templates, so `$store->get(AppConfig::class)` autocompletes as `AppConfig`.
- 🛡 **Frozen by design** — request-time mutations roll back at shutdown; a
  request can never corrupt the persisted state for the next one.
- 🔭 **Observable** — `phpinfo()` shows exactly what is persisted, and the
  engine itself enforces the `ext-ffi` dependency.
- 🧩 **Pure PHP** — powered by [lisachenko/z-engine](https://github.com/lisachenko/z-engine),
  which gives PHP direct FFI access to its own engine internals. Nothing to
  compile, nothing to install beyond Composer.

The proof lives in CI: a FastCGI gate drives hundreds of *real* requests through
one worker and asserts the object graph is built exactly once, survives every
RINIT/RSHUTDOWN boundary with its cycles intact, and sheds every mutation — plus
a 5000-cycle soak over a nested/diamond/cyclic graph that fails on a single
leaked byte of request memory, and a second 5000-cycle soak that persists and
drops a whole graph per cycle and fails if the process does not get its memory
back.

Two features share one persistent module:

1. **Persistent PHP objects** (above) — see `demos/demo-objects.php`.
2. **Shared C data** (the original demo): module globals with a raw C structure
   surviving the request boundary — counters, flags, fixed-size tables. See
   `demo.php`.

## How it works

`persist(ClassName::class, $object)` deep-converts the whole object **graph**
reachable from that root into persistent (malloc) memory and returns a **new
canonical root instance**:

- every `zend_object` of the graph becomes a malloc-backed clone with its
  refcount pinned high, flagged non-collectable (the cycle collector never scans
  it) and with both shutdown passes over the object store suppressed;
- **nested objects** are converted recursively and their slots retargeted at the
  clones. The walk is keyed by the source object address, so an object reached
  twice is persisted **once**: diamonds keep their shared identity, cycles
  (including self-references) terminate instead of recursing;
- objects that are **already persistent** are not converted at all — they join
  the new graph by reference (see *Sharing objects between graphs* below);
- **strings** become persistent interned strings living in non-refcounted zval
  slots — the engine shares the pointer and copy-on-writes on mutation, exactly
  like real interned strings;
- **arrays** are rebuilt as sealed immutable persistent hashtables — reads are
  zero-copy, writes copy-on-write into request memory; objects found inside them
  join the graph as well;
- scalars are plain byte copies.

Per request the store walks its **global object table once** and re-registers
every persisted object in `EG(objects_store)` (fresh handle each via
`zend_objects_store_put`), rebinds each object's class entry by name with a
per-object layout-signature guard (a graph may mix classes), then materializes
the canonical root instance of every entry — the rest of each graph is reached
through property slots pointing at the very same pinned clones. An object shared
by several entries is handled exactly once. At request shutdown — before the
engine tears the object store down — every object is rolled back to its own
persisted snapshot and detached, so the engine never touches persistent memory
with the request allocator.

### Frozen semantics

Persisted state is **frozen graph-wide**: you can mutate the root and any nested
object freely during a request (mutations land in request memory), but at
request end every property of every graph object is rolled back to the state
captured by `persist()` — including slots you repointed at brand-new objects. To
change the persisted state, call `persist($name, $newObject)` again with fresh
state. Mutation sync-back is planned as an opt-in mode.

### What can be persisted

Objects of **userland classes** with scalar, string, array and object properties
(nested arrays and nested object graphs welcome). The persister rejects — with
the exact property path, e.g. `$root::$services[db]::$pdo` — anything whose
identity or lifetime cannot outlive a request:

| Rejected | Why |
|---|---|
| Resources | tied to request-scoped handles |
| Closures | internal class carrying request-bound scope |
| References | not supported yet |
| Internal classes (`ArrayObject`, `stdClass`, …) | carry C state the engine frees per request |
| Enums | enum case identity is per-request |
| Dynamic properties | no stable slot to persist into |
| Lazy objects / hooked classes | non-standard handlers or engine flags |
| Persistent objects from a *foreign* registry | no record accounts for their lifetime (objects of **this** store are shared, not rejected) |

### Sharing objects between graphs

A persisted object is a **first-class value**: it can be stored in a plain
request object's property, passed around, and wired into *another* graph you
persist later. The persister recognizes it and references the existing clone
instead of copying it.

```php
$a = $store->persist(Kernel::class, $kernel);       // graph A, includes $a->container
$holder = new RequestScopedThing();
$holder->container = $a->container;                 // first-class reference, perfectly safe

$router = new Router();
$router->container = $a->container;                 // reaches into graph A
$b = $store->persist(Router::class, $router);       // graph B shares that object

$b->container === $a->container;                    // true, in this request and every later one
```

Every persistent object counts how many **entries** reference it (`shares`).
`persist()` increments the count of every member of the new graph *before* the
previous generation of that key is released, so an object present in both never
transits through zero. The same root can also be filed under several keys — two
entries, one fully shared graph.

Foreign persistent objects — clones minted by a different registry, e.g. another
module instance — are still rejected: nothing here can account for their
lifetime.

### Dropping persisted entries

```php
$store->drop(AppConfig::class);   // true if an entry was removed, false if there was none
```

`drop()` removes the entry and gives the process its memory back: for every
member no other entry still references, it frees the object clone, its frozen
snapshot buffer, every sealed array hashtable it owns (nested arrays included)
and all of the registry bookkeeping around it. Shared members survive with their
share count decremented. Persisting over an existing key is the same operation
with a new graph put in place first, so a long-running worker that re-persists a
key does not accumulate generations.

**Alias safety.** Userland copies of an object zval bump the refcount even on a
pinned persistent clone, so a live alias is detectable. If anything in the
current request still holds an object that `drop()` would free, it throws a
`RuntimeException` naming the class and changes nothing at all — release the
references (`unset()` them, or let their scope end) and drop again:

```php
$config = $store->get(AppConfig::class);
$store->drop(AppConfig::class);   // RuntimeException: the request still holds ...
unset($config);
$store->drop(AppConfig::class);   // true
```

**Array and string payloads are NOT covered by that check.** Immutable arrays and
persistent strings live in *non-refcounted* zvals — that is what makes them
zero-copy to read — so a copy taken earlier in the same request leaves no trace
behind. After `drop()` returns, do not use copies of that entry's array or string
values taken earlier in the same request. Across requests the question cannot
arise: request memory dies with its request.

**What is not reclaimed.** Persistent strings (property values, array keys and
elements, class names, registry keys) are deliberately never freed, for exactly
the reason above: nothing can prove a request-side copy is gone. They are also
not deduplicated, so the leak is proportional to the number of strings persisted
over the process lifetime — roughly 4 kB per persist/drop cycle of a five-object
graph in `tools/soak-drop.php`, against ~13 kB per cycle if nothing were
reclaimed. A real content-keyed persistent intern table would remove this
residue; it is the next iteration.

### Deployment model

- **Scope: one worker process.** This is per-process persistent memory, not
  cross-process shared memory. Each FPM/RoadRunner worker has its own copy.
- **Blessed setups**: worker loops (RoadRunner, FrankenPHP worker mode, Swoole)
  or classic FPM with **`opcache.preload`** (stable class entries). Without
  preload, classes are rebound by name on `attach()` and a property-layout
  signature guards against class-shape drift; a changed class layout throws.
- The instance returned by `persist()`/`get()` is the canonical one — existing
  references to the source object are not retargeted (zvals embed object
  pointers directly; that is physics, not policy). The same holds for every
  nested object: reach them through the returned root.
- Persisted graphs **may** share objects: wiring a clone from one `persist()`
  call into another root references it instead of copying it. Each object counts
  how many entries reference it, and only objects nobody references anymore are
  freed by `drop()`.
- The registry layout is versioned in the module globals; a worker still holding
  a registry written by an older build is rejected on `boot()` instead of being
  misread — restart the worker after upgrading.
- `__destruct` never runs for persisted objects, `spl_object_id` changes per
  request, and an opcache restart invalidates permanently-interned string
  pointers shared with persisted state — restart workers together with opcache.

### Introspection

The module surfaces its state in `phpinfo()` / `php -i` (persisted entry names,
entry count and the number of live object clones in the `shared_objects`
section; `PersistentStore::objectCount()` returns the same number) and declares
an engine-enforced dependency on `ext-ffi`. At request end a module-level
`requestShutdown()` callback acts as a belt-and-braces detach on top of the
store's own shutdown function.

## API

```php
$store = PersistentStore::boot();          // register/reattach the persistent module
$store->persist(User::class, $o): User;    // convert + return canonical instance (T of class-string<T>)
$store->attach(): array;                   // class-string => instance for this request (idempotent)
$store->get(User::class): ?User;           // canonical instance or null
$store->has(User::class): bool;
$store->drop(User::class): bool;           // remove the entry + reclaim what nobody shares
$store->objectCount(): int;                // live persistent clones (shared ones counted once)
$store->detach(): void;                    // runs automatically at request shutdown
```

## Testing

```bash
composer install
vendor/bin/phpunit                       # unit + lifecycle tests
php -d ffi.enable=1 tools/soak.php       # 5k attach/mutate/detach cycles, flat-memory gate
php -d ffi.enable=1 tools/soak-drop.php  # 5k persist/attach/drop cycles, reclamation gate
bash tools/request-boundary/run.sh 100   # real RINIT/RSHUTDOWN boundaries via php-cgi/FastCGI
php -d ffi.enable=1 demos/demo-objects.php
php -d ffi.enable=1 demo.php             # original shared C data demo
```

CI runs all of the above on every push and pull request.

## Requirements

- PHP ~8.4 (NTS) with `ext-ffi`
- `lisachenko/z-engine` — temporarily pinned to the
  `claude/shared-objects-dag-memory-nq462w` branch, which adds the persistent
  free primitives (`Core::persistentFree()`, `PersistentHashTable::destroy()`,
  `HashTable::deleteIndex()`) this release needs; back to `dev-master` (or the
  `8.4` release line once tagged) as soon as that lands
