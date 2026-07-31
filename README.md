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
one worker and asserts the object is built exactly once, survives every
RINIT/RSHUTDOWN boundary, and sheds every mutation — plus a 5000-cycle soak that
fails on a single leaked byte of request memory.

Two features share one persistent module:

1. **Persistent PHP objects** (above) — see `demos/demo-objects.php`.
2. **Shared C data** (the original demo): module globals with a raw C structure
   surviving the request boundary — counters, flags, fixed-size tables. See
   `demo.php`.

## How it works

`persist(ClassName::class, $object)` deep-converts the object's state into
persistent (malloc) memory and returns a **new canonical instance**:

- the `zend_object` itself becomes a malloc-backed clone with its refcount
  pinned high, flagged non-collectable (the cycle collector never scans it) and
  with both shutdown passes over the object store suppressed;
- **strings** become persistent interned strings living in non-refcounted zval
  slots — the engine shares the pointer and copy-on-writes on mutation, exactly
  like real interned strings;
- **arrays** are rebuilt as sealed immutable persistent hashtables — reads are
  zero-copy, writes copy-on-write into request memory;
- scalars are plain byte copies.

Per request the store re-registers each object in `EG(objects_store)` (fresh
handle via `zend_objects_store_put`), rebinds the class entry by name with a
layout-signature guard, and materializes the canonical PHP instance. At request
shutdown — before the engine tears the object store down — every object is
rolled back to its persisted snapshot and detached, so the engine never touches
persistent memory with the request allocator.

### Frozen semantics (v1)

Persisted state is **frozen**: you can mutate the object freely during a
request (mutations land in request memory), but at request end every property
is rolled back to the state captured by `persist()`. To change the persisted
state, call `persist($name, $newObject)` again with fresh state. Mutation
sync-back is planned as an opt-in mode.

### What can be persisted

Objects of **userland classes** with scalar, string and array properties
(nested arrays welcome). The persister rejects — with the exact property path —
anything whose identity or lifetime cannot outlive a request:

| Rejected | Why |
|---|---|
| Nested objects / closures | v1 limitation — flatten or persist separately |
| Resources | tied to request-scoped handles |
| References | v1 limitation |
| Internal classes (`ArrayObject`, …) | carry C state the engine frees per request |
| Enums | enum case identity is per-request |
| Dynamic properties | no stable slot to persist into |
| Lazy objects / hooked classes | non-standard handlers or engine flags |

### Deployment model

- **Scope: one worker process.** This is per-process persistent memory, not
  cross-process shared memory. Each FPM/RoadRunner worker has its own copy.
- **Blessed setups**: worker loops (RoadRunner, FrankenPHP worker mode, Swoole)
  or classic FPM with **`opcache.preload`** (stable class entries). Without
  preload, classes are rebound by name on `attach()` and a property-layout
  signature guards against class-shape drift; a changed class layout throws.
- The instance returned by `persist()`/`get()` is the canonical one — existing
  references to the source object are not retargeted (zvals embed object
  pointers directly; that is physics, not policy).
- `__destruct` never runs for persisted objects, `spl_object_id` changes per
  request, and an opcache restart invalidates permanently-interned string
  pointers shared with persisted state — restart workers together with opcache.

### Introspection

The module surfaces its state in `phpinfo()` / `php -i` (persisted object count and
names in the `shared_objects` section) and declares an engine-enforced dependency on
`ext-ffi`. At request end a module-level `requestShutdown()` callback acts as a
belt-and-braces detach on top of the store's own shutdown function.

## API

```php
$store = PersistentStore::boot();          // register/reattach the persistent module
$store->persist(User::class, $o): User;    // convert + return canonical instance (T of class-string<T>)
$store->attach(): array;                   // class-string => instance for this request (idempotent)
$store->get(User::class): ?User;           // canonical instance or null
$store->has(User::class): bool;
$store->detach(): void;                    // runs automatically at request shutdown
```

## Testing

```bash
composer install
vendor/bin/phpunit                       # unit + lifecycle tests
php -d ffi.enable=1 tools/soak.php       # 5k attach/mutate/detach cycles, flat-memory gate
bash tools/request-boundary/run.sh 100   # real RINIT/RSHUTDOWN boundaries via php-cgi/FastCGI
php -d ffi.enable=1 demos/demo-objects.php
php -d ffi.enable=1 demo.php             # original shared C data demo
```

CI runs all of the above on every push and pull request.

## Requirements

- PHP ~8.4 (NTS) with `ext-ffi`
- `lisachenko/z-engine` `dev-master` (or the `8.4` release line once tagged —
  z-engine versions follow the PHP version they target)
