# The shared-memory model: what holds, what breaks, and why

This document is the distilled result of the validation sweep that preceded the fork-shared
arena (EPIC [#15]) — the questions that had to be answered before any of this could be built,
and the answers, with their consequences for anyone consuming the package. The measurements
quoted here were taken on PHP **8.4.19** and **8.5.9** (NTS, linux-x64) and are recorded in
the [spike-gate verdict on #15][gate]; the claims this package actually depends on are
promoted to tests under `tests/Shm/` and re-run on both minors on every change.

It is deliberately written as a list of *laws and their symptoms*: most failure modes in this
territory do not raise, they corrupt — quietly, in a different process, much later.

[#15]: https://github.com/lisachenko/php-shared-data-extension/issues/15
[gate]: https://github.com/lisachenko/php-shared-data-extension/issues/15#issuecomment-5303807403

---

## 1. Sharing is fork-only, and that is a design constraint, not an implementation gap

The arena is one `mmap(NULL, size, PROT_READ|PROT_WRITE, MAP_SHARED|MAP_ANONYMOUS, -1, 0)`
region created **before any fork**. Anonymous shared memory is shared with the *children* of
the process that mapped it, never with unrelated processes, so the whole worker family
inherits the mapping at the **same virtual address**. That is what makes an address a
portable value: eight bytes handed to a sibling mean the same object there.

Three further things must be address-stable for a shared `zend_object` to be usable at all,
and all three are stable **only** by virtue of the fork:

| What | Why it is stable | When it stops being stable |
|---|---|---|
| the arena base | inherited mapping | a process that maps its own arena post-fork gets private memory that merely looks identical |
| `std_object_handlers` | one process-lifetime global, inherited | never (a request-lifetime handlers block would dangle — persistence rejects such objects) |
| `zend_class_entry *` | inherited, if the class was loaded **before** the fork | a class first autoloaded inside one worker lands wherever that worker happened to put it |

**Consequence.** Every class whose instances travel through the arena must be loaded before
the fork (`opcache.preload`, or simply touching the class). A class first autoloaded in a
child is fine *inside* that child and meaningless to its siblings.

## 2. The mutation contract: what is atomic and what only looks atomic

A PHP value slot is a 16-byte `zval`: an 8-byte payload word plus a 4-byte `u1.type_info`
word (plus `u2`). Writing one is **two stores**, and an unlocked reader can observe the two
halves from different generations:

- **A naturally aligned 8-byte read/write never tears.** Over 2M+ unlocked reads of a pointer
  slot being swapped, every observation was old-or-new, never a mix.
- **A 16-byte `zval` is not atomic.** ~1.3 % of unlocked reads saw a payload word and a type
  word from different writes; at the PHP level, a three-property update was observed
  half-applied in 2.7–3.8 % of unlocked reads.

Hence the law this package implements and enforces:

> Readers take the **same stripe lock** as the writer whenever the slot's *type* can change,
> or whenever more than one slot participates in the value being read. A single aligned
> 8-byte pointer read of a slot whose type is fixed by contract may skip the lock.

Visibility itself is not the problem: a scalar property written in one process is visible in
the others **immediately** — no flush, no barrier, no re-attach. The worst staleness observed
under a stripe lock was ~110–210 µs, which is lock-wait and scheduler latency, not memory
visibility.

The mirror image of that result is the reason the arena exists at all: the same object in
ordinary `malloc` memory is **copy-on-write** across a fork. A child writing `counter = 424242`
reads its own value back and the parent still sees the old one, forever, with no error
anywhere. Shared mutation without shared memory silently does nothing.

**Critical-section discipline.** While an arena mutex is held: word loads and stores only. No
engine call that can allocate, no userland callback, no Fiber suspension, nothing that can
throw. Values are encoded before the lock and materialized after it. (This is also why an
`EOWNERDEAD` recovery can simply declare the lock consistent: a torn state is not reachable
from a critical section that is one aligned store.)

## 3. Three fields inside a shared `zend_object` are per-process

A `zend_object` living in shared memory has fields that describe *the process reading it*
rather than the object. Writing them into shared memory is what a naive implementation does,
and each one fails differently:

| Field | Failure | Remedy |
|---|---|---|
| `handle` | Collides **by construction**: forked children inherit one object-store free list and hand out *identical* handle numbers for different objects. A sibling's `unregister()` then recycles the wrong slot. | per-process side table keyed by arena address; the shared field is overwritten with a sentinel so no process can trust it |
| `properties` | Written by **engine C code on read-shaped operations** — a request-heap pointer stored inside shared memory. A sibling dereferencing it segfaults (confirmed). | forced `NULL` on attach, and scrubbed after any triggering operation; the pointer is never dereferenced in shared mode |
| `ce` | Fork-stable only for classes loaded before the fork (§1). | rebound per process at attach and recorded in the side table; the shared field is advisory |

Only `handlers` is genuinely shareable: `std_object_handlers` is one address for the whole
family.

**The `properties` trigger list** — engine C code caches a rebuilt property bag inside the
object on all of these, none of which look like writes:

`get_object_vars()` · `var_dump()` · `json_encode()` · `(array)` cast · `serialize()` ·
`debug_zval_dump()` · `ReflectionObject` property enumeration

A policy of "we never write it" cannot hold, because *we* are not the one writing it. The
only workable policy is: assume it will be written, force it back to `NULL` in the process
that triggered it, and never dereference what is found there.

**`spl_object_id()` on a shared object is meaningless.** It reads `handle` out of the shared
struct. The stable cross-process identity is the **arena address**
(`PersistentStore::sharedIdOf()`); the registry keys everything by it, and nothing by handle.

## 4. The `arData` law: engine table growth is silent corruption

A `zend_array` grows by reallocating its bucket block (`HT_GET_DATA_ADDR`) through the
engine's allocator. For a table living in the arena that means the block moves into the
**private heap of whichever worker happened to fill it** — and the resize writes the new
private pointer into the **shared struct before it aborts**. The process that grew the table
dies with `SIGABRT`; its siblings read plausible garbage with no signal at all.

Therefore:

- every arena-resident table is **pre-sized** at creation and never grown; an insert past
  capacity is **refused** (typed exception) rather than attempted;
- on recovery, `HT_GET_DATA_ADDR(ht) = arData - HT_HASH_SIZE(nTableMask)` is re-derived and
  bounds-checked against the arena — the pointer is the only honest evidence that a resize
  happened;
- `nTableMask` is declared unsigned and **used signed** (`-(2 * nTableSize)`), so it must be
  sign-corrected before the multiplication or the check computes nonsense;
- growth-capable *user* collections in shared memory are purpose-built containers of
  fixed-size records (`Ipc\SharedArray`), not `zend_array`s. Plain-array properties of a
  shared object stay sealed immutable for the same reason.

## 5. Robust process-shared mutexes: `EOWNERDEAD` is not an edge case

All cross-process synchronization uses `pthread_mutex_t` placed in the arena with
`PTHREAD_PROCESS_SHARED | PTHREAD_MUTEX_ROBUST` (FFI offers no atomics or CAS, so there is no
lighter option). Verified: a mutex in `MAP_SHARED` memory really does exclude another
process, a SIGKILLed owner hands the lock to the next taker as `EOWNERDEAD` (130), and the
recovered lock is fully usable afterwards.

Two rules, both non-negotiable:

- **handle `EOWNERDEAD` at every lock site**, calling `pthread_mutex_consistent()` before
  unlocking. Skipping it poisons that mutex arena-wide with `ENOTRECOVERABLE` (131) —
  *permanently*;
- **robust is not optional**: a non-robust mutex whose owner died is eternal `EBUSY`, and a
  supervisor that kills workers is a normal part of the deployment this targets.

`sizeof(pthread_mutex_t)` is 40 bytes on x86-64 glibc; the arena measures it at runtime and
reserves a 64-byte cache line per lock, so a platform with a larger mutex is a clean typed
failure instead of overlapping neighbours.

## 6. Memory accounting: leak-until-teardown, on purpose

The arena is a **bump allocator**: one cursor, moved forward under a lock, never moved back.
There is no free list, no per-block header, and blocks are reclaimed only when the region
dies — which happens when the **creating process exits** and the kernel takes the mapping
back. Nothing unmaps it earlier, and that is a correctness requirement rather than laziness:
PHP runs shutdown functions *before* it destroys the symbol table and the object store, so any
variable still holding a shared object is released after an unmap armed there would have
happened — a segfault waiting for the right test order. A child never unmaps either
(`destroy()` in a child is a deliberate no-op); its copy of the mapping goes away with the
process.

What that costs, concretely:

- **children never free arena memory** — every reclamation path is disabled for arena-backed
  state, and an attempt is a typed refusal rather than a `free()` of memory the process heap
  never handed out;
- **strings are interned per write.** A mutable string property that is rewritten N times
  consumes N string blocks; the old bytes leak until teardown, because a reader that took the
  pointer before the swap must still be able to follow it. A workload streaming unbounded
  distinct strings has to be sized for it. Scalars, object references and shared-array slots
  cost nothing per write;
- **exhaustion is a normal, typed outcome** (`ArenaException::exhausted()`), not a crash.

## 7. Closures: provenance, never inspection

- **Pre-fork closures are safe by address** — a closure compiled before the fork barrier has
  the same address and the same `op_array` in every worker.
- **Post-fork closures are unsafe in siblings.** On 8.4 a stale address segfaulted; on 8.5 —
  worse — the address held a *different, perfectly valid* `Closure` and the wrong function
  executed. There is no shape check that can distinguish the two cases, so closures are
  rejected on **provenance** (compiled before the fork barrier) and never on inspection.
- The real blocker for arena-resident closures is not the `op_array` (a few hundred
  enumerable bytes) but `run_time_cache__ptr` and `static_variables_ptr__ptr`, which point
  into the **per-request** arena. Arena-resident closures would share those slots between
  processes; they must be re-minted per process, by the same side-table mechanism §3
  describes for objects.

That is why provenance is **recorded rather than inferred**: `Ipc\ClosureProvenance` is a
pre-sized table in the arena where the arena-owning process registers closures by name before
it calls `markForkBarrier()`, and every registration after that moment — or from a worker — is
refused. A registered closure travels as the address of its **record**; the closure object
itself is never copied, because a pre-fork one needs no copy. Captures are held to the value
contract at registration, and by-reference captures and declared statics are refused outright:
after the fork each worker writes its own copy-on-write copy of such a slot, so the divergence
would be silent.

Arena-resident clones of **post-fork** closures remain out of scope; the inventory, the
per-process slots that decide it and the verdict are in
[closure-cloning.md](closure-cloning.md), tracked as Phase B of [#20]. Work created after the
fork still travels as Task objects (data), not as callables.

[#20]: https://github.com/lisachenko/php-shared-data-extension/issues/20

## 8. How the solution is implemented, primitive by primitive

The laws above decide the shape of everything below; this section is the map from law to
code, so a reader can go from "why is it like this" to the file that does it.

### The arena (`Shm\Arena`, `Shm\ArenaAllocator`, `Shm\Libc`)

One `MAP_SHARED|MAP_ANONYMOUS` mapping created before the fork, laid out as: header words
(magic, layout version, size, bump cursor, creator pid, measured mutex size, roots capacity) ·
a bank of 64 robust process-shared mutexes on 64-byte cache lines (slot 0 = allocator,
slot 1 = roots directory, 2… = consumer stripes) · a roots directory of 64 named addresses ·
the bump-allocated payload.

- `allocate()` moves the shared cursor under the allocator mutex — that is the entire
  allocator, per §6, and it is why an allocation from a child is safe while a free is not;
- `sizeof(pthread_mutex_t)` is *measured* at runtime rather than assumed, and checked against
  the 64-byte slot stride;
- `stripeFor($address)` hashes an address onto one of the 62 consumer stripes, which is how an
  unbounded number of small structures share a bounded bank of locks;
- **no public method returns `FFI\CData`**: views are bound once per process at map time, and
  callers see integers and strings. That is not tidiness — it is what keeps every critical
  section to aligned word access (§2);
- `ArenaAllocator` implements z-engine's `Allocator` seam, so the *persister* mints object
  clones, snapshots, strings, sealed arrays **and their bucket keys** out of the arena. There
  is no half-way: one malloc-backed block inside a shared graph is a pointer a sibling cannot
  follow.

### The registry (`Registry`, `Shm\ArenaRegistryLayout`)

Named graphs and a process-wide object table, both living in the arena and published in the
roots directory — a forked child finds them with nothing but the mapping. Tables are pre-sized
and never grown (§4): an insert that would resize is refused with the table named, and
recovery re-derives `HT_GET_DATA_ADDR` and bounds-checks it against the arena. Object records
carry the object's **role** (frozen or shared-mutable), so every worker reads the same
lifecycle rules for the same address.

### The per-process side table (`SideTable`, `PersistentStore`)

The remedy for §3, keyed by arena address: `handle` (from z-engine's
`ObjectEntry::register()`, after which the shared field is overwritten with a sentinel), `ce`
(rebound per process at attach), `properties` (forced `NULL` at attach and scrubbed after any
triggering operation, never dereferenced). Identity is exposed as
`PersistentStore::sharedIdOf()` — the arena address — and never as a handle.

### Mutation (`SharedObjectHandle`)

The synchronized write path for a graph persisted with `mutable: true`. Every write validates
and encodes its payload *before* taking the object's stripe lock; the critical section is
payload word then type word and nothing else (§2). Strings are interned into the arena and
swapped as one aligned 8-byte pointer, the previous block leaking by design (§6); object
references may only point at another object of the same arena; array slots stay sealed.
Direct `$obj->prop = …` writes remain legal, work for scalars and are **unsynchronized** —
the engine gives no write hook to a class rewired to `std_object_handlers`, which is a
deliberate trade, not an oversight. A slot found holding a foreign (non-arena) pointer at
request end is repaired from the frozen image rather than left for a sibling to dereference.

### Value records and the IPC primitives (`Ipc\*`)

Everything crossing a worker boundary is a **16-byte tagged record** — `uint8 tag | 7 pad |
uint64 payload` — where the payload is the value itself for scalars and an *address* for
strings, objects, shared arrays and the records of registered closures. A value with no
address-shaped form (plain array, resource, non-shared object, unregistered closure) is
refused with the remedy named, never encoded: that is the Never-Serialize Rule in one
sentence.

- `SharedChannel` — a ring of records plus sender/receiver waiter tables under its **own**
  dedicated mutex (a structure locked on every operation does not belong on a shared stripe).
  Head and tail are monotonic counters, so fill level is a subtraction; capacity 0 is a true
  cross-process rendezvous; `close()` crosses processes;
  - a rendezvous accepts a value only while a receiver is waiting, and a consumer with its own
    scheduler is never inside `recv()` — so `registerReceiver()`/`cancelReceiver()` (and their
    sender mirrors) let a receiver parked in someone else's event loop count as the partner.
    The registration is a claim about **presence, never about storage**: the record still goes
    into the single ring slot a capacity-0 channel allocates, so a cancellation can always
    succeed — it never has a value in its hands — and a record deposited against a
    registration that is withdrawn a moment later simply waits in the ring for the next
    receiver while its sender stays parked. The whole handshake (register, re-check, deposit,
    cancel) happens under the channel's own mutex, so the happens-before edge is the same
    release/acquire pair it always was;
  - a registration outlives the call that made it, and can therefore outlive its process. Each
    waiter entry packs `owner pid << 32 | wake slot + 1` into one aligned word (two words would
    be a 16-byte record, and those tear — §5), and a rendezvous deposit reaps the entries whose
    owner is gone before it reads the gate, so a dead worker cannot go on standing in for a
    partner. Liveness is `posix_kill(pid, 0)` **plus** the wake registry still naming that pid
    as the slot's owner, because a dead owner's slot is recycled to the next process that
    claims one;
- `SharedArray` — fixed-capacity vector of records, per-instance stripe: the container a
  `zend_array` cannot be (§4);
- `ResultSlotTable` — futures. A slot settles exactly once **per generation**, carrying either
  a value record or a `SharedError` (a persisted three-string object; a `Throwable` can never be
  shared). Slots are **recycled in place** through a free list threaded through the slot records
  — nothing is freed, because nothing here ever can be (§6) — and a slot id is a `SlotTicket`,
  index and generation packed into the 32 bits a wake event has for it. Every verb checks the
  generation, so a handle outliving its release is refused by name instead of being handed the
  next task's answer;
- `SharedMutex` / `AtomicInt` / `SharedWaitGroup` — robust locking, an aligned word with
  stripe-locked read-modify-write (FFI has no CAS), and a counter with waiters;
- `ClosureProvenance` — the register of closures the family may invoke by address. It stores
  provenance, not code: records in the arena, closures wherever they were compiled (§7);
- `WakeRegistry` — one inherited socket pair per process. Sockets carry a fixed 16-byte event
  record (`opcode | tag | id | address`) and never a payload: **signalling, not
  serialization**. Waking is level-triggered and re-checked inside the critical section, so a
  wakeup may be spurious but can never be lost.

## 9. Structural notes worth knowing

- `zval` (16), `Bucket` (32), `zend_array` (56) and `zend_object` (56 + 16·(n−1)) are
  **byte-identical between 8.4 and 8.5** on linux-x64-nts, so the arena layout needs no
  per-minor versioning beyond its own `LAYOUT_VERSION`. Field *offsets* are still read
  through z-engine, which versions them per minor.
- An engine-formatted `zend_object` placed in shared memory attaches as an ordinary PHP
  instance in several processes at once — property reads and writes go through the normal
  engine paths, with no handler tricks.
- The reverse direction works too: a child can bump-allocate and persist a brand-new object
  *after* the fork and hand its address to the parent, which attaches it after the child has
  exited.

## 10. Known limitations, and where they are tracked

| Limitation | Status |
|---|---|
| Classes must be loaded before the fork | by design (§1); documented on `PersistentStore::bootShared()` |
| `spl_object_id()` is not an identity in shared mode | by design; use `PersistentStore::sharedIdOf()` (§3) |
| Plain-array properties of shared objects are immutable | by design (§4); mutable collections are `Ipc\SharedArray` |
| Arena memory is never reclaimed per block | v1 accounting (§6); a shared free list needs cross-process reachability data that nothing here can produce yet |
| String rewrites leak the previous bytes | consequence of §6; a content-keyed persistent intern table is the next iteration |
| Direct `$obj->prop = ...` writes are unsynchronized | by design: the extension rewires shared objects to `std_object_handlers`, so there is no write hook. Scalar writes are visible but racy; a string/array/object written that way stores a request-heap pointer and is restored from the persisted image at detach. The synchronized path is `PersistentStore::mutableHandle()` |
| The shared `handle` field is not the sentinel for a few instructions while a process detaches | inherent: recycling an object-store slot is an engine call and engine calls cannot run under an arena mutex. Nothing in the package reads that field — identity is `sharedIdOf()` (§3) |
| Only closures registered before the fork barrier can be shared | by design (§7); post-fork closures need arena cloning — verdict and inventory in [closure-cloning.md](closure-cloning.md), Phase B of [#20] |
| A borrowed `PersistentHashTable` view cannot re-adopt the external storage block it sits on, so the growth guard is re-derived in this package | z-engine seam follow-up; see the `TODO` in `Registry::assertRegistryRoom()` and [z-engine#223](https://github.com/lisachenko/z-engine/pull/223) |

---

*Evidence: the [spike-gate record on #15][gate] (S8, S12–S17, both minors). The repository
keeps two self-contained spikes — `spikes/s8-robust-pshared-mutex.php` and
`spikes/s15-concurrent-bump-allocation.php` — which run against this package's own `Arena`
from the repository root through Composer's autoloader.*
