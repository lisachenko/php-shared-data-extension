# Shared Data Extension for PHP

[![CI](https://github.com/lisachenko/php-shared-data-extension/actions/workflows/ci.yml/badge.svg)](https://github.com/lisachenko/php-shared-data-extension/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/lisachenko/php-shared-data-extension?include_prereleases)](https://packagist.org/packages/lisachenko/php-shared-data-extension)
[![PHP 8.4 | 8.5](https://img.shields.io/badge/php-8.4%20%7C%208.5-777BB3.svg?logo=php&logoColor=white)](composer.json)
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

### Feature map

| What | Where |
|---|---|
| Persistent objects per worker, frozen by default | [How it works](#how-it-works), `PersistentStore::boot()` |
| One `mmap` arena shared by a whole fork tree | [Fork-shared arena mode](#fork-shared-arena-mode-opt-in-experimental), `PersistentStore::bootShared()` |
| Mutation the whole family sees, opt-in per graph | [Shared mutation](#shared-mutation-opt-in-per-graph), `mutableHandle()` |
| Channels, shared arrays, result slots, locks, counters, wait groups | [IPC primitives](#ipc-primitives-in-the-arena-experimental), `Lisachenko\SharedData\Ipc` |
| Closures invoked in several workers (registered before the fork) | [Shared closures](#shared-closures-registered-before-the-fork), `Ipc\ClosureProvenance` |
| Why any of it is shaped this way — the laws, the measurements, the failure modes | [docs/shared-memory-model.md](docs/shared-memory-model.md) |
| The rules a contributor (human or agent) must not break | [AGENTS.md](AGENTS.md) |

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
| Closures | internal class carrying request-bound scope — share one with `Ipc\ClosureProvenance` instead of persisting it |
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

### Fork-shared arena mode (opt-in, experimental)

Everything above is **per-worker** memory: each FPM/RoadRunner process rebuilds its own
copy. Arena mode removes that limit for a family of processes that descend from one
parent. The state is persisted into a single `mmap(MAP_SHARED|MAP_ANONYMOUS)` region
created **before the fork**, so every worker sees the very same objects at the very same
addresses — no serialization, no cache round-trip, no copy.

```php
use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Shm\Arena;

$arena = Arena::create();                    // 64 MB by default, or SHARED_DATA_ARENA_SIZE
$store = PersistentStore::bootShared($arena);

$config = $store->persist(AppConfig::class, buildExpensiveConfig());
$address = $store->addressOf(AppConfig::class);   // eight bytes that mean the same thing
                                                  // in every process of the family
for ($worker = 0; $worker < 4; $worker++) {
    if (pcntl_fork() === 0) {
        $childStore = PersistentStore::bootShared($arena);   // recovery, no globals write
        $config     = $childStore->get(AppConfig::class);    // the SAME object, not a copy
        // ... or attach an address a sibling sent over a socket:
        // $object = $childStore->attachObject($address);
        exit(0);
    }
}
```

What changes under the hood: every block the store mints — registry tables, object clones,
frozen snapshots, interned strings, sealed arrays and their keys — comes out of the arena
instead of malloc. The registry tables are **pre-sized and never grown**: the engine grows
a full hashtable by reallocating its data block into the private heap of whichever worker
filled it, writing that pointer into the shared struct *before* anything fails, so the
tables refuse the insert with a typed `ArenaException` instead. Sizes come from
`SHARED_DATA_ENTRY_CAPACITY` / `SHARED_DATA_OBJECT_CAPACITY`.

The arena is bump-allocated and **leak-until-teardown**: blocks are never returned
individually (`drop()` still removes entries and share-accounts them, it just does not free
arena memory), and the region lives until the creating process exits — nothing unmaps it at
request shutdown, because the engine releases the last references to shared objects *after*
shutdown functions have run. `watermark()`
exposes exactly how much has been handed out, and exhaustion is a typed exception, never a
crash. Cross-process locking uses a bank of 64 `PTHREAD_PROCESS_SHARED | PTHREAD_MUTEX_ROBUST`
mutexes inside the arena, so a SIGKILLed worker hands the lock on (`EOWNERDEAD`) instead of
wedging the pool.

**Per-process engine state.** Three fields of a `zend_object` describe the process reading
it, not the object, and they live in a per-process side table rather than in shared memory:
the object-store `handle` (forked children inherit one free list and are handed *identical*
numbers, so the shared field is overwritten with a sentinel and identity is
`$store->sharedIdOf($object)` — the arena address), the class entry (rebound per process;
classes must still be loaded **before the fork**, since a shared object carries one `ce` for
the family), and the dynamic-property cache. That last one is written by engine C code on
`get_object_vars()`, `var_dump()`, `json_encode()`, `(array)`, `serialize()`,
`debug_zval_dump()` and `ReflectionObject` — a request-heap pointer deposited in shared
memory — so it is forced `NULL` at attach and never dereferenced. Inspect a shared object
through `$store->inspect($object, fn ($o) => var_dump($o))`, or call
`$store->scrubProperties($object)` afterwards.

### Shared mutation (opt-in per graph)

By default a persisted graph is **frozen**: request-time mutations are rolled back at
request end. Pass `mutable: true` and the graph keeps everything that makes a persistent
clone safe — the refcount pin, `GC_PERSISTENT|GC_NOT_COLLECTABLE`, non-refcounted payloads,
sealed arrays — and gives up the rollback, so what a worker writes stays written for the
whole family:

```php
$counters = $store->persist(Counters::class, new Counters(), mutable: true);
$handle   = $store->mutableHandle($counters);

$handle->writeScalars(['hits' => 1, 'misses' => 0]);   // one critical section
$handle->writeString('lastRoute', '/checkout');        // interned in the arena, pointer swapped
$handle->writeReference('owner', $otherSharedObject);  // arena objects only

[$hits, $misses] = array_values($handle->readScalars(['hits', 'misses']));
```

Every write takes the object's stripe mutex and does nothing inside it but store the payload
word and then the type word; every value is validated and interned *before* the lock.
Declared property types are enforced by the write path, because the engine never sees the
assignment. What is refused: a plain-array slot (a shared `zend_array` can never grow — use
`Ipc\SharedArray`), and a reference to an object that is not itself in this arena.

A direct `$object->hits++` still compiles and still reaches shared memory — the extension
rewires shared objects to `std_object_handlers`, so there is no write hook to intercept it.
For scalars that is merely **unsynchronized** (visible everywhere, racy). For a string,
array or object it stores a pointer into the writing process's request heap, which no
sibling may follow: such a slot is restored from the persisted image at detach instead of
being left behind. Use the handle for anything that has to be correct.

Reader/writer contract for anything you build on the arena directly: a naturally aligned
8-byte read never tears, but a 16-byte `zval` is two stores — readers take the same stripe
mutex as the writer whenever a value's *type* can change or more than one slot participates.
Every claim in this section, with its evidence and its consequences, is written up in
[docs/shared-memory-model.md](docs/shared-memory-model.md).

### IPC primitives in the arena (experimental)

Shared memory answers "where does the value live"; it says nothing about "whose turn is it"
and "is it there yet". `Lisachenko\SharedData\Ipc` adds the primitives that do, and they are
themselves structures in the arena — a channel, an array, a mutex, a counter, a wait group
and a table of result slots, all found by address (or by a name in the arena roots
directory) rather than inherited as PHP state.

```php
use Lisachenko\SharedData\Ipc\{SharedChannel, ResultSlotTable, ValueCodec, WakeRegistry};
use Lisachenko\SharedData\Shm\{Arena, ArenaAllocator};

$arena     = Arena::create();
$store     = PersistentStore::bootShared($arena);
$allocator = new ArenaAllocator($arena);
$codec     = new ValueCodec($allocator, $store);
$wake      = WakeRegistry::create($arena);              // socket pairs, created PRE-FORK
$jobs      = SharedChannel::create($allocator, $codec, $wake, 64, name: 'jobs');
$results   = ResultSlotTable::create($allocator, $codec, $wake, 1024);

$slot = $results->allocateSlot();
if (pcntl_fork() === 0) {
    [$job, $ok] = $jobs->recv();                        // parks on the socket, wakes on an event
    $results->complete($slot, process($job));           // writes a record, pokes the waiter
    exit(0);
}
$jobs->send($sharedObject);                             // an address, never a copy
$value = $results->await($slot)->value;                 // read straight out of shared memory
```

Values move as **16-byte records**: `uint8 tag | 7 pad | uint64 payload`, where the payload
is the value itself (`int`, `float`, nothing at all for `null`/`bool`) or an arena address
(an interned `zend_string`, a shared `zend_object`, a `SharedArray`, the record of a shared
closure). A value with no address-shaped form — a plain array, a resource, an object this
family does not share, a closure nobody registered — is refused with
`NotShareableValueException` naming the remedy. Nothing is ever encoded: there is no
`serialize()`, igbinary or JSON on any data path, and the test suite proves it by shadowing
every encoding function in the package's namespaces.

The sockets carry **only** fixed 16-byte event records `{opcode, tag, slot/channel id,
address}` — signalling, never payload; a scalar's record carries a zero where an address
would be. Waking is level-triggered: a waiter registers in the structure's waiter table and
re-checks the state inside the same critical section, so a wakeup can be spurious but never
lost, and every blocking loop also re-polls on a bounded slice.

| Primitive | What it is |
|---|---|
| `SharedChannel` | ring of records + waiter tables under a dedicated robust mutex; capacity 0 is a true cross-process rendezvous; `close()` crosses processes (receivers drain, then `[null, false]`; senders throw) |
| `SharedArray` | fixed-capacity vector of records, `ArrayAccess`/`Countable`/`IteratorAggregate`, stripe-locked |
| `ResultSlotTable` | futures: `allocateSlot()` / `complete()` / `completePanic()` / `await()`, with panics travelling as a shared `SharedError` object |
| `SharedMutex` | robust process-shared mutex with trylock-and-backoff, `EOWNERDEAD` recovered and reported |
| `AtomicInt` | one shared cell: plain aligned get/set, stripe-locked `add()`/`compareAndSet()` |
| `SharedWaitGroup` | counter plus waiter table; `add()`/`done()`/`wait()`, negative counts throw |
| `WakeRegistry` | one inherited socket pair per process, the notification plane everything parks on |

Blocking here is a spin loop over the notification descriptor, which is the honest primitive
a package with no scheduler can offer: every primitive also exposes its non-blocking half
(`trySend()`/`tryRecv()`/`tryLock()`/`readSlot()`) plus `notificationStream()`, so a
coroutine runtime can park a Fiber in its own event loop instead.

### Shared closures (registered before the fork)

A closure compiled **before the fork** is valid in every worker: the family inherited the
memory it lives in, so its address means the same function everywhere. A closure compiled
*after* the fork is the opposite, and it does not fail loudly — a stale address was observed
holding a different, perfectly valid `Closure` that then executed the wrong function. Nothing
about the object tells the two apart, so this package decides on **provenance** and never on
inspection: a closure travels if, and only if, it was registered before the fork barrier.

```php
use Lisachenko\SharedData\Ipc\ClosureProvenance;

$closures = ClosureProvenance::create($allocator, $store);      // pre-fork, like the arena
$factor   = 3;

$record = $closures->registerSharedClosure('scale', static fn (int $n): int => $n * $factor);
$closures->markForkBarrier();                                   // registration closes here

if (pcntl_fork() === 0) {
    $scale = $closures->resolve($record);                       // or ->closure('scale')
    exit($scale(14) === 42 ? 0 : 1);                            // runs in this worker
}
$jobs->send($closures->closure('scale'));                       // travels as a record address
```

The record — closure address, function witness, name, bound `$this` — lives in the arena; the
closure itself is never copied. What is refused, with the reason named: registering after the
barrier or from a worker, a bound `$this` that is not a shared object, a captured plain array
or request object, a capture by reference and a declared `static` variable (each worker would
copy-on-write its own copy of those slots and diverge in silence). Cloning *post-fork* closures
into the arena is a separate problem with its own verdict —
[docs/closure-cloning.md](docs/closure-cloning.md).

### Deployment model

- **Scope: a fork tree, not a single process.** The default store is per-worker
  persistent memory — each FPM/RoadRunner worker builds its own copy. Arena mode
  widens that to **one family of processes descended from one parent**: the
  region is mapped before the fork, so every worker sees the same objects at the
  same addresses. Attaching from an *unrelated* process is permanently out of
  scope — class entries, object handlers and the arena base would all differ,
  and none of that fails loudly.
- **Frozen by default, mutable by opt-in.** A persisted graph rolls its
  request-time mutations back at shutdown unless you pass `mutable: true`, which
  trades the rollback for state the whole family keeps.
- **Signalling is separate from data.** IPC primitives (channels, shared arrays,
  result slots, locks, counters, wait groups) live in the arena; sockets carry
  fixed 16-byte event records only.
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
$store->persist(User::class, $o): User;    // convert + return canonical instance; the key is a NAME,
                                           // ::class by convention so get() keeps its inference
$store->persistInstance($o): User;         // per-instance graph named by its own root address:
                                           // any number of one class live at once, none upserts another
$store->attach(): array;                   // name => instance for this request (idempotent)
$store->get(User::class): ?User;           // canonical instance or null
$store->has(User::class): bool;
$store->drop(User::class): bool;           // remove the entry + reclaim what nobody shares
$store->dropInstance($o /* or address */): bool; // same, for an instance graph
$store->objectCount(): int;                // live persistent clones (shared ones counted once)
$store->detach(): void;                    // runs automatically at request shutdown

// fork-shared arena mode (opt-in)
$arena = Arena::create();                  // pre-fork, fixed size, leak-until-teardown
$store = PersistentStore::bootShared($arena);
$store->addressOf(User::class): ?int;      // the eight bytes that travel between workers
$store->attachObject($address): object;    // the receiving half, in any process of the family
$arena->watermark(): int;                  // arena bytes handed out so far
$arena->contains($address, $length): bool; // is this pointer still shared memory?

// IPC primitives (all of them live in the arena; every one has a non-blocking half)
$wake    = WakeRegistry::create($arena);              // pre-fork; sockets are inherited
$channel = SharedChannel::create($allocator, $codec, $wake, $capacity);
$channel->send($value, $timeout): bool;               // trySend() never blocks
$channel->recv($timeout): array;                      // [value, true] | [null, false]; tryRecv() too
$channel->close(): void;                              // crosses processes, drains first
$channel->notificationStream();                       // park your own event loop on this
$slots = ResultSlotTable::create($allocator, $codec, $wake, $capacity);
$slots->allocateSlot(): int;
$slots->complete($id, $value): void;                  // completePanic($id, SharedError::capture(...))
$slots->await($id, $timeout): SlotResult;             // readSlot() never blocks

// shared closures (registration is the acceptance test; everything else is refused)
$closures = ClosureProvenance::create($allocator, $store);   // pre-fork
$closures->registerSharedClosure($name, $closure): int;      // returns the record address
$closures->markForkBarrier(): void;                          // closes registration for the family
$closures->closure($name): Closure;                          // resolve($address) by address
$codec = new ValueCodec($allocator, $store, $closures);      // lets registered closures travel
```

## Testing

```bash
composer install
vendor/bin/phpunit                       # unit + lifecycle tests (forking arena suites included)
php -d ffi.enable=1 tools/soak.php       # 5k attach/mutate/detach cycles, flat-memory gate
php -d ffi.enable=1 tools/soak-drop.php  # 5k persist/attach/drop cycles, reclamation gate
bash tools/request-boundary/run.sh 100   # real RINIT/RSHUTDOWN boundaries via php-cgi/FastCGI
php -d ffi.enable=1 demos/demo-objects.php
php -d ffi.enable=1 demo.php             # original shared C data demo
```

CI runs all of the above on every push and pull request, on PHP 8.4 and 8.5.
`spikes/` holds the runnable evidence behind the engine claims this package
rests on; [AGENTS.md](AGENTS.md) states the rules a change has to keep.

## Requirements

- PHP 8.4 or 8.5 (NTS) with `ext-ffi`
- `lisachenko/z-engine` — required as `8.4.x-dev || 8.5.x-dev`; z-engine tracks
  one PHP minor per line, and Composer resolves the line matching the running
  PHP (the `8.4` branch on PHP 8.4, `master` — aliased `8.5.x-dev` — on PHP 8.5)
