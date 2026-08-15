# Spike verdicts — zero-serialization shared-object arena

Agent **C1** (spike/validation). EPIC: [php-shared-data-extension#15](https://github.com/lisachenko/php-shared-data-extension/issues/15).

Everything here was run **on both supported minors** and every check is green on both:

| | PHP 8.4.19 (NTS) | PHP 8.5.9 (NTS) |
|---|---|---|
| S12 cross-process mutation | 9 OK / 0 FAIL | 9 OK / 0 FAIL |
| S13 pre-sized arData | 10 / 0 | 10 / 0 |
| S14 attach side effects | 8 / 0 | 8 / 0 |
| S16 string swap | 5 / 0 | 5 / 0 |
| S17 closures across fork | 4 / 0 | 4 / 0 |
| S8/S15 mutex + bump | 6 / 0 | 6 / 0 |

**Headline: the premise holds.** An engine-formatted `zend_object` placed in
`MAP_SHARED|MAP_ANONYMOUS` memory can be attached as an ordinary PHP instance in several
forked processes at once, and a plain `$obj->prop = ...` in one process is immediately
visible to the others. Everything below is about the sharp edges around that fact.

---

## How to reproduce

```
spikes/
  lib/bootstrap.php                 harness: PSR-4 autoload for ZEngine\ + Lisachenko\SharedData\,
                                    libc FFI binding (mmap / robust pshared mutexes), fork helpers
  S12_cross_process_mutation.php
  S13_shared_ardata.php
  S14_attach_side_effects.php
  S16_string_swap.php
  S17_closures_across_fork.php
  S08_S15_mutex_and_bump.php
  run-all.sh                        runs everything on php8.4 + php8.5, logs into out/
  out/*.log                         captured evidence for the numbers quoted below
  zengine-85/                       shallow clone of z-engine `master` (the 8.5.x-dev line)
```

```bash
./run-all.sh                                   # both minors
php8.4 -d ffi.enable=1 -d opcache.jit=off S12_cross_process_mutation.php
```

**Environment note the implementing agents need:** the checkout at `/home/user/z-engine` is
the **8.4 branch only** (`SUPPORTED_PHP_VERSION_ID = [80400, 80500)`, `include/8.4` only), so
running any z-engine-backed code under php8.5 against it aborts in `Core::init()`. The spikes
resolve the 8.5 line from a scratch clone of z-engine `master` (`spikes/zengine-85`). This is
a sandbox artifact, not a design finding — but any CI leg that exercises E1–E5 on 8.5 needs
Composer to actually resolve `8.5.x-dev`, and a local path repo pointing at the 8.4 checkout
will silently skip instead of failing.

Structural fact worth recording: `zval` (16), `Bucket` (32), `zend_array` (56),
`zend_object` (56 + 16·(n-1)) and the whole `zend_op_array` field order are **byte-identical
between 8.4 and 8.5** on linux-x64-nts (`diff` of the two generated `engine.h` core sections
is empty). Nothing in the arena layout has to be versioned per minor beyond what z-engine
already versions.

---

## S12 — cross-process mutation visibility · **GREEN**

### Evidence

**A. Hand-built zval slot, 10⁶ locked writes, 1 locked reader + 1 unlocked reader**

```
A1 locked reader: 1090181 reads, 0 inconsistent observations
    locked reader last observed generation 1000000 of 1000000
A2 unlocked reader: 8615400 reads, 182045 value/mirror mismatches,
                                  116039 value-vs-type mismatches
```
(8.5: 977876 / 0, and 289045 + 139907 mismatches.)

**B1. The motivating negative result — today's malloc path**

```
persistent clone at 0x55b1314e5e20, object size 88 bytes (malloc/pemalloc heap)
child wrote counter=424242 (child read back 424242)
[ OK ] B1 parent still sees counter=100
       CONFIRMED: malloc memory is COW across fork — mutations are NOT shared
```

**B2. The same object memcpy'd into the arena and re-anchored**

```
arena object at 0x7f5ede601000, handle 36, spl_object_id=36, class=S12Holder
LOCKED reader:   169107 reads, 0 inconsistent, highest counter observed 200000 of 200000,
                 max value age 166.4 us
UNLOCKED reader: 2607122 reads, 89409 inconsistent (3.43%)
parent now reads counter=200000 ratio=50000.0 flag=false (written only by a child)
```

**C. Reverse direction (E1 acceptance #2).** A child bump-allocated a *brand new*
`S12Holder` into the arena post-fork, applied the `persistentClone` GC surgery to the arena
block, and sent 8 bytes down a pipe. The parent attached it after the child had exited and
read `counter=31337 ratio=2.5 flag=true`.

### Consequences

- **E1 (#16):** the arena + bump-allocate + publish-address-over-a-pipe path works end to
  end, in both directions, on both minors. `PersistentObjectFactory::persistentClone()`'s GC
  surgery (`PIN_BASELINE`, `GC_OBJECT|GC_NOT_COLLECTABLE|GC_PERSISTENT`,
  `IS_OBJ_DESTRUCTOR_CALLED|IS_OBJ_FREE_CALLED`, `handlers = std_object_handlers`,
  `properties = NULL`) is exactly right for an arena block too — the only thing the Z1
  allocator seam has to change is *where the bytes come from*.
- **E2 (#17):** scalar in-place property writes are visible cross-process **immediately** —
  no flush, no barrier, no re-attach. Under the stripe lock a reader observed the writer's
  value at most **~110–210 µs old** (max over ~170k locked reads; that is lock-wait plus
  scheduler latency, not a memory-visibility delay).
- **E2/E3 — design correction:** *a 16-byte zval is not atomic.* The value word and the
  `u1.type_info` word are two separate stores, and an unlocked reader observed the two
  halves from different generations **116 039 times in 8.6 M reads (~1.3 %)**. At the PHP
  level a 3-property update was observed half-applied in **2.7–3.8 %** of unlocked reads.
  Readers **must take the same stripe lock as the writer** whenever the *type* can change
  or more than one slot participates. This lands directly on E3's "16-byte tagged record"
  contract: a `SharedChannel` ring slot **cannot** be published with a plain store of the
  record — publish the payload first, then the tag, with the tag store as the release point,
  or keep the whole ring operation under the ring mutex (recommended for v1).

---

## S13 — pre-sized arData in shared memory · **GREEN (with a hard trap, documented)**

A 64-entry hash `zend_array` was built with `PersistentHashTable`, sealed with
`markImmutable()`, and relocated into the arena — struct **and** the single engine data
block, with `arData` re-pointed:

```
nTableMask=-128  HT_HASH_SIZE=512  HT_DATA_SIZE=2048  one block of 2560 bytes
arena table: struct at ...400, block at ...1000, arData at ...1200 (inside arena: true)
zval type_info = 0x7   (GC_IMMUTABLE => non-refcounted IS_ARRAY)
```

The relocation arithmetic implementers need (mirrors `zend_types.h`):

```
HT_HASH_SIZE(nTableMask) = (uint32_t)(-(int32_t)nTableMask) * sizeof(uint32_t)
HT_DATA_SIZE(nTableSize) = nTableSize * sizeof(Bucket)            // 32 bytes
HT_GET_DATA_ADDR(ht)     = (char*)ht->arData - HT_HASH_SIZE(ht->nTableMask)
```
`nTableMask` is declared `uint32_t` but is used signed — read it signed or the hash size
comes out astronomically wrong.

### Evidence

- `count()` = 64, `$a['k7']` = 70, `array_sum()` over `foreach` = 20160, and z-engine's own
  `HashTable` view agrees — all through a real PHP array zval pointing at arena memory.
- Concurrent load: one child overwrote `bucket[7].val` in place (raw `lval` + `IS_LONG`)
  200 000 times under the mutex while two siblings read.
  ```
  value reader:      157828 locked reads, 0 anomalies, highest 200000 of 200000
  structural reader: 78054 full foreach+count walks, 0 anomalies
  ```
- **The trap.** A child inserted past capacity into a *non-sealed* arena table:
  ```
  free(): invalid pointer
  growth child: killed by signal 6 (SIGABRT)
  parent now reads ht->arData = 0x5575f5e9b1c0  (inside arena2: false)
  post-growth foreach child: exit 0
  it walked 8 entries summing to 280; the shared struct claims nNumOfElements=8 nTableSize=16
  ```
  Two distinct failures in one event: (1) the resize `pefree()`s the *old* block, which is
  arena memory the process allocator never handed out → **SIGABRT**; (2) before aborting the
  engine had already written the new `arData` — a pointer into that child's private heap —
  **into the shared struct**, so a surviving sibling walks a table that looks perfectly
  healthy and returns **silent garbage, with no signal at all**.

### Consequences

- **E1 (#16):** the "registry tables never grow via the engine" guard is not a nicety, it is
  the difference between a crash and silent corruption. Guard shape that works: record
  `arData` at seal time and assert on every access that `HT_GET_DATA_ADDR(ht)` is still
  inside the arena bounds — the pointer change is cheap to detect and is the *only*
  observable symptom in the silent case. Pre-size with the Z1 external-arData API and never
  hand a growable table to userland.
- **E2 (#17):** confirms "plain-array property mutation stays forbidden". In-place *value*
  overwrite of an existing bucket is safe and fast; anything that can trigger
  `zend_hash_do_resize` (insert, `zend_hash_add`, packed→hash conversion) is not.
- **E3 (#18):** `SharedArray` as a **fixed-capacity vector of 16-byte records** rather than a
  wrapped `zend_array` is the right call; this spike is the evidence for why.
- Bucket **keys** in a relocated table still point at malloc-interned `zend_string`s. That
  survives fork by COW but would not survive a non-forked attach — arena-intern keys too
  (S16 shows the mechanics).

---

## S14 — per-process side effects of attach · **RED for the current field layout; the side table is mandatory**

### A. `obj->handle` is clobbered

```
parent put() -> handle 35, obj->handle=35, spl_object_id=35
child 0: put() returned handle 36 ... child 1: put() returned handle 36
parent afterwards: obj->handle=36, spl_object_id($parentInstance)=36 (parent's real slot is 35)
parent's object store slot 36 currently holds: a DIFFERENT live object
```

Note the detail that makes this worse than a race: both children were handed **the same
handle number 36**, because each inherited the same COW'd `EG(objects_store).free_list_head`.
Handles are not merely clobbered, they *collide by construction*. After the children ran,
the parent's `spl_object_id()` returns a slot number belonging to someone else, and
`ObjectStore::recycle()` at detach would push a **foreign** slot onto the free list.

### B. `obj->properties` — the dynamic-properties pointer hazard

```
inside child A: properties 0x0 -> get_object_vars(2 vars) -> 0x7f81c76c4310
                             -> var_dump -> same -> json_encode -> same -> (array) cast -> same
PARENT now reads obj->properties = 0x7f81c76c4310   (child A is gone; that is child A's private heap)
```

A single `get_object_vars()` — an operation that reads like a pure read — writes a
request-heap `HashTable*` into the shared struct, and it stays there. Two follow-ups:

- a **forked sibling** survived but got the wrong answer: `get_object_vars()` returned
  **1 property instead of 2**, `var_dump()` printed a 49-byte dump. Silent garbage, no signal
  — because fork gave it the same COW heap layout, so the address happened to be mapped.
- a process that did **not** inherit that heap (simulated by pointing `properties` at an
  address mapped nowhere) died with **SIGSEGV on both 8.4 and 8.5**.

### C. `obj->ce` and `obj->handlers`

```
parent: std_object_handlers=0x556beb7b5920, S14Holder ce=0x7fec5b604018
child 0: handlers=SAME  S14Holder ce=SAME  post-fork S14LateClass ce=0x7fec5747d368
child 1: handlers=SAME  S14Holder ce=SAME  post-fork S14LateClass ce=0x7fec57476928
```

`std_object_handlers` is address-identical in every forked process — **safe to keep inside
the shared struct**, exactly as E2 assumes. A class entry loaded **before** the fork is also
address-identical. But a class first declared **after** the fork lands at a different address
in each process as soon as their compile histories differ (child 0 declared 40 decoy classes
first). So `ce` is fork-stable **only** for the pre-fork-loaded subset.

### Consequences

- **E2 (#17), item 2 — confirmed necessary and correctly scoped.** `handle`, `ce`,
  `properties` out of the shared struct into a per-process side table keyed by arena address;
  `handlers` stays. Add these concrete requirements:
  - **`properties` must be forced back to `NULL` in the shared struct** on every attach, and
    the object must be barred from ever caching a rebuilt bag there. The cheapest correct
    shape is a `get_properties_for`/`get_debug_info` handler pair on the shared class that
    builds a *request-local* table and never writes `obj->properties` — otherwise the hazard
    is re-armed by the first `var_dump()` any worker ever runs. A "children never write the
    shared struct" rule is **not** enough here: the write is performed by engine C code
    inside `zend_std_get_properties`, not by our code.
  - The full trigger list observed: `get_object_vars()`, `var_dump()`, `json_encode()`,
    `(array)` cast. Also reachable via `serialize()`, `debug_zval_dump()`,
    `ReflectionObject::getProperties()`, object iteration, `property_exists()` on dyn props.
  - `spl_object_id()` reads `obj->handle` **directly** — it cannot be fixed by a side table
    alone. If per-process identity matters to user code, the shared class needs its own
    identity story (document it, or expose `Arena::idOf($obj)`), because
    `spl_object_id()`/`spl_object_hash()` on a shared object will be whatever the last
    attaching process wrote.
  - `attach()` must **not** call `zend_objects_store_put` on a struct another process may be
    attaching concurrently. Either serialize attach under the object's stripe lock and
    immediately restore the field from the side table, or (better) stop letting the engine
    write it at all: `put()` then rewrite `obj->handle` back to a sentinel and serve the real
    handle from the side table.
- **E1 (#16):** the registry must key everything by **arena address**, never by handle —
  handles are not stable, not unique, and not even distinct between processes.

---

## S16 — string swap visibility · **GREEN**

Two `zend_string`s were interned into the arena (via
`StringEntry::persistentInterned()` + memcpy — the `GC_IMMUTABLE|IS_STR_INTERNED` header is
already the shape a shared string needs: engine copies it into zvals without refcounting and
copy-on-writes on mutation, so no process ever bumps a refcount or frees it in shared
memory). A shared object's `string $name` slot was pointed at string A, then swapped
300 000 times between A and B.

```
LOCKED   reader: 267587 reads (A=135833 B=131754), 0 torn, max staleness 205.7 us
UNLOCKED reader: 2022502 reads, 0 torn pointers, 0 unexpected string values,
                 max staleness 207.8 us
```
(8.5: 251818 / 0 torn, 2 616 769 unlocked reads / 0 torn.)

Control: the same swap on an 8-byte slot **straddling a 4 KiB page boundary** produced no
tearing on this CPU over 1.5–2.8 M reads either — the ISA still gives no guarantee there, so
the alignment invariant stays, it just isn't cheaply falsifiable on this hardware.

### Consequences

- **E2 (#17):** the "string property = arena-intern new bytes + pointer swap under lock"
  contract is sound. Concretely: **a naturally-aligned 8-byte pointer swap never tears**, so
  the lock is buying *ordering between slots* and *lifetime safety*, not per-pointer
  atomicity. A single-string-slot reader may legitimately read without the lock and will get
  either the old or the new string, never a mix — useful for hot read paths, and worth
  stating explicitly so nobody adds locking that isn't needed.
- Stale reads without the lock are bounded by the writer's publish rate, not by anything
  architectural: the maximum age of the value an unlocked reader saw was **~208 µs**,
  statistically identical to the locked reader's. Taking the lock does **not** make a reader
  fresher; it makes a *multi-slot* read consistent.
- Lifetime rule the numbers imply: the swapped-away string must **not** be freed. With
  leak-until-teardown v1 that is automatic; if a reclaimer ever appears, an unlocked reader
  holding the old pointer is the hazard to design against.
- Keep every arena `zval` 8-byte aligned at minimum (the natural `zend_object` layout gives
  `properties_table[i]` at `40 + 16i`, which is 8-aligned when the object block is
  16-aligned — bump-allocate objects 16-aligned and this is free).

---

## S17 — closures across fork · **GREEN for Phase A, AMBER for Phase B**

### (a) Pre-fork closures — safe

Static closure, closure with `use` scalars, and a `$this`-bound closure, each invoked
100 000 times concurrently in two children:

```
child 0: 0 wrong results, checksum 55100050000, closure spl_object_id 24
child 1: 0 wrong results, checksum 55100050000, closure spl_object_id 24
```

### (b) Post-fork closure invoked by a sibling — unsafe, both failure modes captured

Child A allocated ballast, built a closure, and sent its address down a pipe; child B
materialized an `IS_OBJECT` zval at that address and invoked it.

- **8.4:** `child B: killed by signal 11 (SIGSEGV)` — markers show it got as far as
  `$alien instanceof \Closure === true` and died inside the invoke.
- **8.5:** child B invoked **a completely different function** — the address held the spike's
  own fork-body closure, which ran with its captured variables `null` and died with
  `TypeError: spl_object_id(): Argument #1 must be of type object, null given`.

The 8.5 outcome is the more instructive one: the address *was* a live `Closure` in child B,
just not the intended one. There is no validity check that could have caught it.

### (c) Pointer inventory of a `zend_closure`

For a trivial `function (int $x) use ($base) { static $calls = 0; ... }`, **14 of 20**
pointer fields are non-NULL on 8.4 (12 on 8.5):

| field | 8.4 | note |
|---|---|---|
| `std.ce` / `std.handlers` | set | process-stable (Closure is an internal class) |
| `op_array.opcodes` | set | 10 ops × 32 B = 320 B |
| `op_array.literals` | set | 1 × 16 B (NULL on 8.5 for this closure) |
| `op_array.vars` | set | 3 × 8 B |
| `op_array.arg_info` | set | 1 × 32 B |
| `op_array.function_name`, `.filename` | set | `zend_string*` |
| `op_array.refcount` | set (8.4) / NULL (8.5) | shared op_array refcount |
| `op_array.live_range` | set | |
| `op_array.static_variables` | set | a `HashTable*` |
| `op_array.static_variables_ptr__ptr` | set | **per-request slot** |
| `op_array.run_time_cache__ptr` | set | **per-request slot** |
| `op_array.scope`, `.attributes`, `.dynamic_func_defs`, `.try_catch_array` | NULL | for this closure |
| `this_ptr`, `called_scope` | NULL | set for bound closures |

A pre-fork closure keeps **identical addresses** in the child (verified:
struct, `opcodes`, `literals`, `static_variables` all match).

### Consequences

- **E5 (#20) Phase A — GREEN, ship it.** Pre-fork closures are safe to persist by address and
  to transport as `OBJ` records. The op_array, literals and captured statics are read-only
  COW data; `run_time_cache` is per-process private after fork (each child COW-copies its own
  page on first write), which is exactly why (a) is correct.
- **E5 Phase B — AMBER, and the blocker is not the op_array bytes.** Deep-cloning the
  *compiled* graph is small and tractable: ~400 bytes for the closure above, and every field
  is enumerable through z-engine. The blocker is that **`run_time_cache__ptr` and
  `static_variables_ptr__ptr` point into the request arena, not into the op_array**. Put a
  closure struct in the arena and those two slots become *shared*, so two processes would
  write each other's polymorphic-cache entries and each other's `static` variables. Any
  Phase B design must re-mint both per process — which is a per-process side table for
  closures, structurally the same mechanism E2 builds for objects. Recommend: implement
  Phase A now, and scope Phase B as "arena-clone the immutable compiled graph + per-process
  cache/statics side table", or record the documented not-soundly-achievable verdict that
  #20's acceptance criteria already allow for.
- **E3 (#18):** the typed rejection for post-fork closures must be **unconditional and
  address-based** (was this closure compiled before the fork barrier?). It must not be a
  "does this look like a Closure" check — S17(b) on 8.5 shows a wrong address passing every
  plausible validity test and then executing the wrong function.

---

## S8 / S15 — quick confirmations (X1 owns the in-repo versions) · **GREEN**

### S8 — robust pshared mutex, owner died

```
holder: killed by signal 9 (SIGKILL)
parent pthread_mutex_lock() returned 130 after 6.5 us   (EOWNERDEAD == 130)
consistent()=0 unlock()=0 then lock()=0 unlock()=0
```

Two controls, both of which the implementation must encode as rules:

- **skipping `pthread_mutex_consistent()` is fatal and permanent**: unlock without it and the
  next `lock()` returns **131 = ENOTRECOVERABLE**, forever, for every process. A missed
  recovery handler takes the whole arena down, not just one critical section.
- a **non-robust** pshared mutex whose owner dies is simply stuck: `trylock()` returns
  **16 = EBUSY** and `lock()` would block forever. `PTHREAD_MUTEX_ROBUST` is not optional.

Layout confirmed as assumed: glibc x86-64 `pthread_mutex_t` = 40 bytes,
`pthread_mutexattr_t` = 4; the spikes use 64-byte slots (one cache line) and that works
cleanly with `FFI::cdef(..., null)` resolving libc through the process image.

### S15 — concurrent bump allocation, 4 children

```
S15a (under the mutex): 100000 records, 11199712 bytes carved,
                        0 overlaps, 0 duplicate offsets, 0 corrupted blocks
S15b (no mutex):         82309 records (expected 100000), 2031 overlaps,
                        765 duplicate offsets, 3124 corrupted blocks
```

Verification is not just interval arithmetic: every block was `memset` with its owner's tag
and re-read afterwards, so an overlap shows up as *content* corruption too.

### Consequences

- **E1 (#16):** the stripe-mutex bank design is sound. Add to the acceptance criteria:
  **every `pthread_mutex_lock()` call site must handle `EOWNERDEAD`** — check the return
  code, run the invariant repair for that stripe, call `pthread_mutex_consistent()`, and only
  then proceed. A wrapper that ignores the return value is a latent arena-wide deadlock. It
  is worth a debug assertion that no lock helper discards its `int` result.
- Because a worker can die mid-critical-section, the E2 rule "critical sections are memcpys
  and pointer swaps only, no engine calls that allocate, no user callbacks, no Fiber
  suspension" is what makes `EOWNERDEAD` recovery *possible at all*: a section that can only
  be half-done in a bounded, structurally checkable way is one you can repair. Keep that rule
  enforced by assertion, as #17 already plans.

---

## Consolidated design corrections for the implementing agents

1. **A 16-byte zval is not atomic.** Value and `type_info` are separate stores; ~1.3 % of
   unlocked reads observed mismatched halves over 8.6 M samples. Type-changing writes and
   multi-slot updates require the stripe lock on **both** sides. (E2, E3 tagged records.)
2. **An aligned 8-byte pointer swap is atomic.** Single-slot string/object-reference reads
   may skip the lock; they get old-or-new, never a mix. Don't over-lock hot read paths. (E2)
3. **`obj->properties` is written by engine C code on read-shaped operations.** A
   "we never write it" policy cannot hold it. Force it `NULL` and intercept
   `get_properties_for`/`get_debug_info` on the shared class, or a single `var_dump()` in one
   worker segfaults the next one. Confirmed SIGSEGV on 8.4 and 8.5. (E2)
4. **`obj->handle` collides, it does not merely race.** Forked children inherit the same
   object-store free list and hand out the *same* handle. `spl_object_id()` on a shared
   object is unreliable by construction — decide and document the identity story. (E2)
5. **`obj->ce` is fork-stable only for pre-fork-loaded classes.** Two workers that autoload
   in different orders place the same class at different addresses. Side-table it; keep only
   `handlers` in the shared struct. (E2)
6. **Table growth is silent, not loud.** A resize writes a private-heap `arData` into the
   shared struct *before* it aborts; siblings then read plausible garbage with no signal.
   Bounds-check `HT_GET_DATA_ADDR(ht)` against the arena on access — the pointer change is
   the only observable symptom. (E1, E3)
7. **`EOWNERDEAD` must be handled at every lock site.** Skipping `pthread_mutex_consistent()`
   poisons the mutex with `ENOTRECOVERABLE` permanently, arena-wide. (E1)
8. **Post-fork closures cannot be validated by inspection.** On 8.5 a stale address held a
   *different, perfectly valid* `Closure` and executed it. Reject on provenance (compiled
   before the fork barrier?), never on shape. (E5, E3)
9. **Phase B's real cost is not the op_array.** ~400 bytes of enumerable compiled data; the
   blocker is `run_time_cache__ptr` and `static_variables_ptr__ptr` pointing into the request
   arena. Arena-resident closures share those per-request slots between processes — they must
   be re-minted per process. (E5)
