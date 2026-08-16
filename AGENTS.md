# Working on php-shared-data-extension

This package gives PHP objects that outlive a request and, in arena mode, a **fork-shared
mutable object arena**: worker processes descended from one parent exchange real PHP values —
scalars, strings, objects, arrays, and closures registered before the fork — by **address**,
coordinated by IPC primitives that are themselves structures in shared memory. It is pure PHP
on top of [z-engine](https://github.com/lisachenko/z-engine) and FFI; there is no C extension
to compile, and every rule below exists because the engine punishes breaking it *silently*.

Read [`docs/shared-memory-model.md`](docs/shared-memory-model.md) once before changing
anything under `src/Shm/`, `src/Ipc/` or the shared paths of `PersistentStore`. It is the
*why*, with measurements. This file is the *what you must do*, and it does not repeat the
evidence — it cites it.

---

## 1. The Never-Serialize Rule — invariant #1

> No value crossing a worker boundary may pass through `serialize()`, igbinary, JSON,
> `var_export`, or any byte-encoding of PHP value graphs. Cross-worker data is exchanged as
> real PHP values: scalars inline, strings as arena-copied `zend_string` addresses,
> arrays/objects as addresses of shared objects living in a fork-shared mmap arena. Sockets
> may carry only wake bytes and fixed-size control records (opcode + tag + address/slot id) —
> signaling, not serialization.

This is not a performance preference. A serialized graph is a *copy* with its own identity and
its own lifetime, and every guarantee in this package — one object, one address, one
lifecycle, visible mutation — dies with the first copy. If a value has no address-shaped form,
it is **refused** with the remedy named (`NotShareableValueException`), never encoded.

The rule is tested, not asserted: `tests/Ipc/NotificationPlaneForkTest.php` shadows
`serialize`, `unserialize`, `igbinary_*`, `json_*` and `var_export` in the namespaces the data
path runs through, proves the shadows intercept a real call, and then measures a full
round trip at **zero** calls. Keep that guard passing; if you add a namespace to the data
path, add it to the guard.

## 2. Sharing is fork-only

- The arena is `mmap(MAP_SHARED|MAP_ANONYMOUS)`, created **before any fork**. Children inherit
  the mapping at the same address; that is what makes eight bytes a portable value.
- **Attach from an unrelated process is permanently out of scope.** Do not add
  `shm_open`/file-backed mappings "so it also works across the pool": the class entries,
  `std_object_handlers` and the arena base would all differ, and none of that fails loudly.
- **Children never write module globals.** That page is copy-on-write: a child's write becomes
  private and desynchronizes the family with no error. Only the creating process, before the
  fork, writes `globals[0]`/`globals[1]`.
- **Children never free arena memory.** Every free path is armed to refuse
  (`Reclaimer::protect()`), at the last line before the free, with a typed `ArenaException`.
- Classes whose instances travel through the arena must be **loaded before the fork**.

## 3. Two modes, and they do not mix

| Mode | Behaviour |
|---|---|
| **frozen** (default) | request-time mutations are rolled back at `detach()` from the persisted snapshot. Unchanged, byte for byte, from the pre-arena package |
| **shared-mutable** (`persist(..., mutable: true)`, arena only) | no rollback; the role is recorded in the **registry**, so every worker that attaches the address applies the same lifecycle |

Rules for shared-mutable:

- the **side table is mandatory** — `handle`, `ce` and `properties` are per-process fields
  sitting inside a shared struct (model doc §3). Never trust the shared `handle`; identity is
  `PersistentStore::sharedIdOf()`, the arena address;
- synchronized writes go through `PersistentStore::mutableHandle()`, which validates and
  interns **before** taking the object's stripe lock;
- a direct `$object->prop = ...` remains legal and is **unsynchronized** — the object is
  rewired to `std_object_handlers`, so there is no write hook to intercept it. Scalars are
  racy-but-visible; a string/array/object written that way is repaired from the persisted
  image at detach. Document this every time you touch that path rather than pretending it
  cannot happen;
- one object may not belong to a frozen graph and a mutable graph at once — refuse
  (`SharedMutationException::modeConflict()`), never resolve.

## 4. The arData law

**Never allow the engine to grow a shared `zend_array`.** A resize `perealloc`s the bucket
block into the private heap of whichever worker filled it *and writes that pointer into the
shared struct before it aborts* — siblings then read plausible garbage with no signal.

- every arena-resident table is **pre-sized** at creation; an insert past capacity is
  **refused** with the table named, never attempted;
- growth-capable user collections are **purpose-built containers** of fixed-size records
  (`Ipc\SharedArray`), not `zend_array`s. Plain-array properties of shared objects stay sealed;
- **bounds-check on recovery**: re-derive `HT_GET_DATA_ADDR(ht) = arData - HT_HASH_SIZE(mask)`
  and check it against the arena. Read `nTableMask` **signed** — the engine declares it
  unsigned and uses it signed.

## 5. Lock discipline

- `EOWNERDEAD` (130) is handled at **every** lock site, with `pthread_mutex_consistent()`
  before unlocking. Skip it once and the mutex is poisoned arena-wide with
  `ENOTRECOVERABLE` (131), permanently. Never discard a lock result.
- All arena mutexes are `PTHREAD_PROCESS_SHARED | PTHREAD_MUTEX_ROBUST`. Robust is not
  optional: a non-robust mutex whose owner was killed is eternal `EBUSY`.
- A critical section is **memcpys and pointer swaps only**. Inside one: no allocating engine
  call, no userland callback, no channel operation, no Fiber suspension, nothing that can
  throw. Encode before the lock, materialize after it.
- Atomicity contract: an aligned 8-byte read/write never tears; a 16-byte `zval` is two stores
  and **does** tear (~1.3 % of unlocked reads). Take the lock whenever a slot's *type* can
  change or more than one slot participates; a single aligned pointer read may skip it.

## 6. Memory accounting: leak-until-teardown, on purpose

- The arena is a bump allocator: one cursor, moved under a lock, never moved back. Blocks are
  reclaimed when the region dies with the creating process.
- **Nothing unmaps the arena at request shutdown.** This was a real crash: the unmap used to
  be armed as a shutdown function, and PHP destroys the symbol table, the object store and
  every remaining zval *after* shutdown functions run, so a variable still holding a shared
  object was released against unmapped memory — `OK (…)` followed by SIGSEGV, exit 139. No
  ordering inside `Arena` can fix it. `destroy()` stays for a caller who owns the moment; a
  child calling it is a deliberate no-op. Do not re-arm it.
- Every rewrite of a shared string costs a new arena block (a reader may be following the old
  pointer right now). Exhaustion is a typed `ArenaException`, never a crash.
- **Recycling is in-place reuse, never a free.** A fixed-record table may hand a record back out
  — `Ipc\ResultSlotTable` does, through a free list threaded through the slot records themselves
  — but the block stays where it is and nothing is unmapped, so the rule above is untouched. A
  recycled record needs an **identity that changes with it**: without the generation in
  `Ipc\SlotTicket`, an id held one moment too long addresses the next occupant and is answered
  with its data. Every verb re-checks that generation; a mismatch is a typed refusal naming both.
- Gate memory claims with the soaks, and watch the **watermark plateau** rather than the peak:

  ```bash
  php -d ffi.enable=1 -d opcache.jit=off tools/soak.php 5000        # flat-memory gate
  php -d ffi.enable=1 -d opcache.jit=off tools/soak-drop.php 5000   # reclamation gate
  ```

## 7. LAYOUT_VERSION discipline

`Registry::LAYOUT_VERSION` is **5** today. Any change to a record shape, a table's meaning or
what `globals[0]` points at **bumps it**, in the same commit as the change. Readers hard-fail
on a mismatch (`boot()`/`bootShared()` throw and tell the operator to restart the worker) —
that refusal is the feature; never soften it into a migration.

Keep the **version history table in `Registry`'s docblock current**: one line per version,
saying what changed and why a reader of the older layout would be wrong. A consumer structure
that merely lives in the arena payload and adds no field to a registry record does **not** bump
the version (`Ipc\SharedChannel`, `Ipc\SharedArray`, `Ipc\ClosureProvenance`,
`Ipc\ResultSlotTable`); it publishes itself in the roots directory instead. Such a structure
carries its **own** guard when its record shape changes — `ResultSlotTable::FORMAT` is one, a
header word checked at `attach()` — because "does not bump `LAYOUT_VERSION`" must not mean
"changes shape with nothing refusing a reader of the old one".

## 8. Frozen-mode invariants that must survive every change

The arena work must not cost the original package anything. Preserve:

- **alias-safety ordering** — `drop()`/upsert check for live aliases *before* mutating
  anything, and members of the new generation are share-incremented before the old one is
  released, so an object in both never transits through a share count of zero;
- **the refcount pin** — `PIN_BASELINE` on every persistent clone, plus
  `GC_PERSISTENT|GC_NOT_COLLECTABLE`; the same pin is what keeps a registered shared closure
  alive for the whole family;
- **never free persistent strings** — a non-refcounted zval leaves no trace of a request-side
  copy, so a freed string is a dangling pointer nobody can detect;
- **untrack before `persistentFree()`** — the tracked-block registry is a per-request PHP
  static, and freeing a tracked block without untracking it invites a second free of a
  recycled address.

Frozen semantics are covered by tests that must stay byte-identical in behaviour; if a change
makes one of them "need updating", the change is wrong until proven otherwise.

## 9. House rules

- **Style is PER-CS2.0, applied by hand** — no php-cs-fixer is configured in this repository.
  Match the surrounding file: aligned `=` in assignment blocks, one blank line between
  members, trailing commas in multi-line calls, `declare(strict_types=1)` and the file header
  docblock everywhere.
- **Explicit `(int)` casts on FFI field reads.** An `FFI\CData` integer field is not an `int`
  until you say so, and comparisons against one silently do the wrong thing.
- **No public method returns `FFI\CData`.** Callers see integers and strings; pointer views are
  bound once per process at map time. That is what keeps critical sections to word access.
- **Docblocks cite evidence.** A non-obvious rule names its source: a section of
  `docs/shared-memory-model.md`, a numbered correction on
  [EPIC #15](https://github.com/lisachenko/php-shared-data-extension/issues/15), or the test
  that proves it. Prefer explaining the failure mode over restating the code.
- **The suite is PHPUnit, `.phpt`-free.** Cross-process claims are tested in **real forked
  processes** through the harness in `tests/Ipc/IpcTestCase.php` and `tests/Shm/`: children
  answer by **exit code**, never by printing; the parent is the only process that asserts;
  values that must travel do so as arena words or fixed-size socket records. One arena, one
  store and one notification plane per process — the plane must exist before any fork.
- **Both minors, every time.** PHP 8.4 and 8.5 are supported in parallel, each with its own
  z-engine line (`8.4.x-dev || 8.5.x-dev`, resolved by Composer against the running PHP).
  Run the suite on both before proposing a change:

  ```bash
  php8.4 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit   # assert exit code 0
  php8.5 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit   # assert exit code 0
  ```

  Check the **exit code**, not the summary line: a teardown segfault prints `OK (…)` and then
  dies with 139. Run once under `MALLOC_CHECK_=3 MALLOC_PERTURB_=85` for anything touching
  allocation, and `--order-by=random` for anything touching shared state.
- **Spikes are Composer-only.** Everything in `spikes/` bootstraps through
  `vendor/autoload.php` and runs from the repository root against this package's own classes —
  no external harness, no machine-bound paths. Spikes are not part of the suite; claims the
  code depends on get promoted to tests.
- Commits are [Conventional Commits](https://www.conventionalcommits.org/); scopes in use:
  `shm`, `store`, `registry`, `ipc`, `tests`, `ci`, `docs`.

## 10. Closures

- **Provenance-based acceptance only.** A closure may cross a worker boundary if and only if
  it was **registered** with `Ipc\ClosureProvenance::registerSharedClosure()` by the
  arena-owning process **before `markForkBarrier()`**. Registration is the entire acceptance
  test.
- **Never validate a closure by shape.** Not its class entry, not its handlers, not its
  op_array: spike S17 found a stale post-fork address holding a different, perfectly valid
  `Closure` that then executed the **wrong function** (8.5) and segfaulted (8.4). Any check on
  the object is an integrity check on our own record — say so in the code — and never a reason
  to accept one.
- Captures follow the value contract; by-reference captures and declared `static` variables
  are refused, because each worker copy-on-writes its own copy of those slots and the
  divergence is silent.
- Arena-resident clones of post-fork closures are **Phase B** and not implemented. The
  inventory, the two per-request pointers that decide it and the verdict are in
  [`docs/closure-cloning.md`](docs/closure-cloning.md).

---

## Repository map

```
src/PersistentStore.php     boot()/bootShared(), persist/attach/drop/detach, side table, identity
src/Persister.php           graph conversion; threads the arena allocator through EVERY mint
src/Registry.php            named entries + process-wide object table, LAYOUT_VERSION
src/Reclaimer.php           the only place that frees, and the place that refuses to
src/SharedObjectHandle.php  the synchronized write path of a shared mutable object
src/SideTable.php           the three per-process fields, keyed by arena address
src/Shm/                    Arena (mmap + robust mutex bank + roots), allocator, libc, layout
src/Ipc/                    value records, channel, shared array, slots, locks, wake plane,
                            closure provenance
tests/Ipc/, tests/Shm/      forking suites; IpcTestCase is the harness
tools/soak*.php             memory gates; tools/request-boundary/ drives real FastCGI requests
spikes/                     runnable evidence for the engine claims this package rests on
docs/shared-memory-model.md the model, the measurements and the failure modes
docs/closure-cloning.md     Phase A/Phase B of closure exchange, with the Phase B verdict
```
