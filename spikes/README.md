# Spikes

Throwaway-by-intent programs, kept because their *answers* are load-bearing. Everything in
the fork-shared arena rests on behaviour that no documentation guarantees — whether a
`pthread_mutex_t` in `MAP_SHARED` memory really excludes another process, what the engine
does when it grows a hashtable that lives in somebody else's memory, whether a `zval` write
in one process is visible in another. These files are how those questions were answered, and
their logs are the evidence.

They are not part of the test suite: they fork, kill and SIGABRT on purpose, and several of
them are supposed to crash. Run them by hand.

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off spikes/s8-robust-pshared-mutex.php
php8.4 -d ffi.enable=1 -d opcache.jit=off spikes/s15-concurrent-bump-allocation.php
```

## Arena spikes (E1)

| File | Question | Verdict |
|---|---|---|
| `s8-robust-pshared-mutex.php` | Do the arena's `PTHREAD_PROCESS_SHARED` + `PTHREAD_MUTEX_ROBUST` mutexes exclude another process, survive a SIGKILLed owner (`EOWNERDEAD` → `pthread_mutex_consistent`) and stay usable afterwards? | GREEN — all three |
| `s15-concurrent-bump-allocation.php` | Four children, 8000 blocks through one shared bump cursor: any overlap, any block written through by a foreign process? | GREEN — zero overlaps, zero foreign markers |

Both run against this package's own `Arena`, so they double as end-to-end checks of the
class the rest of the epic builds on.

## Validation spikes (`c1/`)

The wider validation sweep that established the premise of EPIC #15, run on PHP 8.4 **and**
8.5 with captured logs in `c1/out/`. `c1/verdicts.md` is the full write-up; the findings that
bind this ticket's implementation:

- **S12** — an engine-formatted `zend_object` in arena memory attaches as an ordinary PHP
  instance in several processes at once, and scalar property writes are visible immediately
  (≈200 µs worst observed staleness). A 16-byte `zval` is **not** atomic: value word and
  type word tore apart in ~1.3 % of unlocked reads, so readers take the stripe lock whenever
  a type can change or several slots participate. A naturally aligned 8-byte pointer read
  never tore.
- **S13** — a hashtable the engine grows in shared memory fails *twice*: `SIGABRT` in the
  process that grew it, and — worse — the new private-heap `arData` is written into the
  shared struct **before** the abort, so surviving siblings read plausible garbage with no
  signal. This is why registry tables are pre-sized, why an insert past capacity is refused
  rather than attempted, and why `Registry` re-derives `HT_GET_DATA_ADDR` and checks it
  against the arena bounds on recovery.
- **S14** — `handle`, `properties` and (for classes loaded after the fork) `ce` are
  per-process fields sitting inside the shared object. Forked children even hand out
  *identical* handle numbers, which is why the registry keys everything by arena address and
  never by handle. Moving those fields into a per-process side table is E2's job (#17); until
  then arena mode carries the limitations listed in `PersistentStore::bootShared()`.
- **S16/S17** — arena-interned strings swap safely under a pointer store; closures are only
  fork-safe when they existed before the fork (E5).

`c1/run-all.sh` reruns the sweep on both minors; it resolves the 8.5 line of z-engine from a
scratch clone, which is why the 8.5 leg is CI's job rather than something reproducible from
this checkout alone.
