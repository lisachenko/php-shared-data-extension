# Shared Data Extension for PHP

Persistent memory for PHP, written in PHP — powered by
[lisachenko/z-engine](https://github.com/lisachenko/z-engine) and FFI.

Two features, one persistent module:

1. **Shared C data** (the original demo): module globals with a raw C structure
   that survives the request boundary in a worker process — counters, flags,
   fixed-size tables. See `demo.php`.
2. **Persistent PHP objects**: full PHP objects whose state survives the request
   boundary, like interned strings do — held in a persistent object table with a
   pinned refcount so the engine never reclaims them. See `demos/demo-objects.php`.

## Persistent objects in 30 seconds

```php
use Lisachenko\SharedData\PersistentStore;
use ZEngine\Core;

Core::init(); // or Core::preload() from opcache.preload

$store = PersistentStore::boot();

if (!$store->has('config')) {
    // Expensive one-time initialization: runs ONCE per worker process
    $config = $store->persist('config', buildExpensiveConfig());
} else {
    // Every later request in this worker: instant recovery, no re-initialization
    $config = $store->get('config');
}
```

Use cases: framework kernel, a booted DI container, configuration/route/metadata
objects — anything expensive to build and read-mostly afterwards.

## How it works

`persist($name, $object)` deep-converts the object's state into persistent
(malloc) memory and returns a **new canonical instance**:

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
$store = PersistentStore::boot();      // register/reattach the persistent module
$store->persist(string $name, object $o): object;  // convert + return canonical instance
$store->attach(): array;               // name => instance for this request (idempotent)
$store->get(string $name): ?object;    // canonical instance or null
$store->has(string $name): bool;
$store->detach(): void;                // runs automatically at request shutdown
```

## Testing

```bash
composer install
vendor/bin/phpunit                     # unit + lifecycle tests
php -d ffi.enable=1 tools/soak.php     # 5k attach/mutate/detach cycles, flat-memory gate
php -d ffi.enable=1 demos/demo-objects.php
php -d ffi.enable=1 demo.php           # original shared C data demo
```

## Requirements

- PHP ~8.4 (NTS) with `ext-ffi`
- `lisachenko/z-engine` (the branch this feature was developed against; a
  tagged release will follow)
