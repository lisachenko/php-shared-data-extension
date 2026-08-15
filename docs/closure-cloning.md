# Closures across processes: what Phase A does, and what Phase B would take

Closure exchange ([#20]) was split in two the moment the spikes came back, and the split is
not about effort — it is about which memory the closure lives in. This document records what
shipped (Phase A), the inventory that decides Phase B, and the verdict on Phase B with the
evidence behind it.

Measurements below were taken on **PHP 8.4.19** and **8.5.9** (NTS, linux-x64) with
`spikes/s17-prefork-closures.php` and the op-array views z-engine exposes.

[#20]: https://github.com/lisachenko/php-shared-data-extension/issues/20

---

## Phase A — pre-fork closures, shared by provenance (shipped)

A closure compiled **before the fork** needs no cloning at all. The `zend_closure`, the
`zend_function` embedded in it, its opcodes, literals and captured values were all laid out by
the parent, so every child maps them at the same addresses; invoking one in a worker touches
that worker's own copy-on-write pages and nothing else.

What the arena carries is therefore not the closure but the **record that vouches for it**:

```text
  closure address | zend_function address | name length | bound $this address | name[32]
```

`Ipc\ClosureProvenance` writes those records before the fork barrier and refuses to write any
afterwards — and refuses registration from a worker at all. That refusal is the entire safety
argument, because the alternative does not exist: spike S17 held a **post-fork** closure
address in a sibling and found a different, perfectly valid `Closure` there, which then
executed the wrong function (on 8.4 the same experiment segfaulted). No property of the object
distinguishes the two cases, so acceptance is registration and never inspection
([EPIC #15, correction #8][gate]).

Consequences a caller sees, all of them tested in `tests/Ipc/SharedClosureForkTest.php`:

| Rule | Why |
|---|---|
| registration happens in the arena-owning process, before `markForkBarrier()` | everything after it is worker-private memory |
| bound `$this` is null or an object of this store | the closure dereferences it in whichever worker invokes it |
| `use`d values follow the ordinary value contract | a capture is a value like any other crossing the boundary |
| by-reference captures and declared `static` variables are refused | both are per-request slots; after the fork each worker writes its own copy and nothing reports the divergence |
| a closure not in the register is refused by `ValueCodec` exactly as before | there is no second acceptance path |

[gate]: https://github.com/lisachenko/php-shared-data-extension/issues/15#issuecomment-5303807403

## Phase B — the inventory

Phase B is the other half of #20: a closure created **after** the fork, cloned into the arena
so that siblings can run it. The op-array graph it would have to copy is small and entirely
enumerable — this is a `static fn (int $n): int => $n * $factor`, the smallest capturing
closure there is:

| Piece | 8.4.19 | 8.5.9 | Notes |
|---|---|---|---|
| `zend_closure` (object + embedded function) | 344 B | 344 B | `zend_object` 56 B of it |
| `zend_op_array` struct | 256 B | 256 B | identical between the minors |
| opcodes (`last` × `sizeof(zend_op)`) | 7 × 32 = 224 B | 5 × 32 = 160 B | `zend_op` is 32 B on both |
| literals (`last_literal` × `sizeof(zval)`) | 1 × 16 = 16 B | 0 B | compiler output differs per minor |
| **enumerable total** | **~496 B** | **~416 B** | plus `arg_info`, live ranges, and the `zend_string`s for names |

z-engine already reads every one of those (`FunctionLikeTrait::getOpCodes()`, `getLiterals()`,
`getArguments()`, `getTryCatchElements()`, `getLiveRanges()`), and `FunctionBodySwap` is prior
art for writing a rebuilt function back into the engine. **Copying ~400–500 bytes into the
arena is not the problem.**

## Phase B — the actual blocker

Two fields of the op-array are **per-request slots**, not data:

```text
  run_time_cache__ptr        polymorphic-call caches, method/property lookup slots, ...
  static_variables_ptr__ptr  the LIVE static-variable table of this execution context
```

Measured on the same closure, they behave exactly as the design fears:

- `run_time_cache__ptr` is already non-null when the closure is created (8.5 also reports
  `cache_size = 8` where 8.4 reports `0`), and it points into request memory;
- on 8.5, `static_variables_ptr__ptr` (`0x7f3f08734cb0`, request memory) is a **different
  table** from `static_variables` (`0x55ac318da280`, the immutable declaration defaults). The
  live table is per execution context by construction; the defaults are shared and read-only.

Move the struct that holds those two pointers into the arena and both slots become **shared
between processes**. That is not a leak or a slowdown, it is a correctness failure of the
worst kind available here: two workers would write each other's cache slots and each other's
static variables with no lock, no signal and no way to notice — the same class of silent
corruption as an engine-grown `arData` (§4 of [the shared-memory model](shared-memory-model.md)).

So Phase B is not "clone the op_array". Phase B is **clone the op_array and re-mint the two
per-process slots in every process that attaches it**, which is precisely the mechanism E2
already built for objects: a side table keyed by arena address, holding the fields that
describe the reader rather than the thing being read
([`SideTable`](../src/SideTable.php), §3 of the model document). A cloned closure would carry:

- an arena-resident, immutable half — struct bytes, opcodes, literals, arg_info, interned
  strings — written once and never mutated;
- a per-process half — run-time cache block, live static-variable table — allocated by each
  attaching process out of **its own** memory and bound before the first call, dropped at
  detach with everything else per-process.

## Verdict

**Achievable, and scoped as a follow-up.** Nothing in the engine layout forbids it: the
graph is enumerable, its size is trivial, z-engine already reads and writes every piece, and
the per-process problem it raises has a solved precedent in this repository. What it needs is
a mechanism of its own — cloning, re-minting, an attach/detach path for closures, plus the
conservative rejection matrix #20 sketches (no first-class-callable of a non-shared instance
method, no references in statics) — and that is a body of work with its own failure modes,
not a variation on Phase A.

**#20 therefore stays open for Phase B.** Phase A is complete and in use; this section is the
documented verdict its acceptance criteria call for, and the starting point for whoever picks
Phase B up.

### If you are picking it up

1. Start from `ZEngine\Type\ClosureEntry` and `ZEngine\Reflection\FunctionLikeTrait` — every
   read you need already exists; `FunctionBodySwap` shows how a rebuilt function is installed.
2. Decide the per-process binding first, not the copy: without a story for
   `run_time_cache__ptr` and `static_variables_ptr__ptr`, a correct copy is still unusable.
   `ZEND_MAP_PTR` indirection (the slot may be a real pointer or a map-ptr offset with the low
   bit set — see `getStaticVariables()`) is part of that story and differs per minor.
3. Keep the acceptance rule of Phase A: a cloned closure is legal because the *cloning
   mechanism* vouches for it, never because it passed an inspection.
4. Bump `Registry::LAYOUT_VERSION` if a cloned closure gains a persisted record — Phase A did
   not, because its table is a consumer structure in the arena payload and adds no field to
   any registry record.

---

*Evidence: `spikes/s17-prefork-closures.php` (13 checks, GREEN on 8.4.19 and 8.5.9),
`tests/Ipc/SharedClosureForkTest.php`, and the [spike-gate record on #15][gate]
(corrections #8 and #9).*
