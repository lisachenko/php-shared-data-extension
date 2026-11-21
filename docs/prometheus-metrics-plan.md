# Prometheus metrics over the fork-shared arena: implementation plan

Status: **proposal**. Nothing here is implemented yet. This document fixes the design, the
split between repositories, the memory layout, the full mapping of Prometheus/OpenMetrics
types onto arena structures, and the order of work with the gate each phase has to pass.

The question it answers: *every worker of a fork family updates metrics in its own hot path;
one scrape must see the sum of all of them, correctly, without serialization, without a
cache round-trip and without making the hot path pay for a lock.*

Measurements below come from `spikes/s18-sharded-metric-cells.php` (PHP 8.5.12 NTS,
linux-x64, 4 vCPU, `opcache.jit=off`). They are a single noisy VM and should be re-run on both
minors before any number here is quoted as a result.

---

## 1. Decision in one paragraph

Metrics are built on **single-writer cells**: every worker claims a *shard*, and every metric
series has one cell (or one group of cells) per shard. A worker only ever writes its own
cells, so an increment is a plain load + store of an aligned 8-byte word — no lock, no CAS, no
fence — and no update can be lost, because no word ever has two writers. A scrape sums the
shards. This is the shape of `LongAdder`, of client_golang's per-CPU histograms, and of
`prometheus_client` (Python)'s multiprocess mode — except that here the shards live in one
shared mapping instead of a directory of mmap files, and the scraper reads them in place.

The generic primitives (shard table, cell planes, a float view on the arena) go into **this**
package; everything that knows what Prometheus is goes into a **new repository** that depends
on it.

## 2. What the measurements say

| Path (one 64-bit cell) | ns / increment | Correct across workers? |
|---|---:|---|
| `Ipc\AtomicInt::add()` — stripe mutex, uncontended | ~500 | yes |
| `Ipc\AtomicInt::add()` — stripe mutex, 4 workers on one cell | 2 600 – 12 000 | yes |
| `Arena::readWord()` + `writeWord()` — checked, unlocked | ~105 | **no** — 4–8 workers on one word kept only 23–54 % of the increments |
| bound `int64_t` / `double` view, one cell per worker, 64 B apart | 16 (alone), ~45–65 (4 workers, incl. fork) | **yes** — exact totals |
| bound view, one cell per worker, **8 B apart** (same cache line) | ~145 | yes, but 3× slower: false sharing |

Three consequences drive the whole design:

1. **The hot path must not lock.** A contended stripe mutex is two to three orders of
   magnitude slower than an owned cell, and a metric is by definition touched on every request.
2. **The hot path must not go through `Arena::readWord()`/`writeWord()` either.** The
   per-call range and alignment checks cost ~6× the store itself. Cells are reached through a
   view bound **once per process** with the bounds checked **once at bind time**.
3. **Shards are laid out shard-major and cache-line aligned.** A worker's cells for every
   series of a family are contiguous and start on a 64-byte boundary, so two workers never
   write into the same line.

A finding from the spike worth keeping in the implementation: a view obtained by casting a
pointer out of a holder `CData` keeps pointing *into the holder*. When the holder goes out of
scope the view writes into freed request memory — no error, the shared cell just never moves.
Cell planes must derive their views from the arena's own mapping view, never from a temporary.

## 3. Where the code lives

| Layer | Repository | Contents |
|---|---|---|
| **Primitives** | `php-shared-data-extension` (this one) | `Shm\Arena` float view + process recovery, `Ipc\ShardTable`, `Ipc\CellPlane`, `Ipc\FixedIndex`. Generic, Prometheus-free, useful to other consumers (coroutine runtime stats, rate limiters) |
| **Metrics** | new, e.g. `lisachenko/shared-metrics` | registry, families, series, all metric types, label handling, exposition (text, OpenMetrics, protobuf), HTTP endpoint and exporter process, collectors, a `promphp` storage adapter |

Why split: the primitives obey this repository's laws (AGENTS.md §1–§7) and need its fork
harness; the metrics layer has its own release cadence, its own compatibility surface (the
Prometheus formats evolve — UTF-8 names, native histograms, OpenMetrics 2.0) and dependencies
this package should never carry (PSR-7/15 interfaces, optional `zlib`). The dependency points
one way: `shared-metrics` → `php-shared-data-extension` → `z-engine`.

The Never-Serialize rule (AGENTS.md §1) is unaffected: the cross-worker data path is words in
shared cells. Rendering the exposition format is *output to Prometheus* from the one process
that answers the scrape, not a value crossing a worker boundary. The metrics repository should
still adopt `SerializationGuard` for its update path, so that "no `serialize`/`json_*` on
`inc()`/`observe()`" is tested rather than promised.

## 4. Core structures

All structures are consumer structures in the arena payload: they publish themselves through
**one** roots-directory entry (`metrics.registry` — the directory has only 64 slots), carry
their own `FORMAT` word checked at attach, and do **not** bump `Registry::LAYOUT_VERSION`
(AGENTS.md §7). Everything is pre-sized at creation and `prefault()`ed before the fork
(AGENTS.md §6). Nothing is ever freed.

### 4.1 Shard table (`Ipc\ShardTable`, this repository)

```text
  header (8 words)    format | capacity | claimed | mutex | overflow cell plane | 3 spare
  entry  (4 words)    pid | start time (clock ticks, /proc/<pid>/stat field 22) | state | claims
                      state: FREE | LIVE | DEAD
```

- **Claim** (first metric operation in a process, then cached for the process lifetime):
  under the table mutex, take a FREE entry, else reap one whose owner is dead, write pid +
  start time + LIVE. Critical section is word stores only; the `/proc` reads that decide
  "dead" happen *before* the lock and are re-validated by comparing the entry's pid/start-time
  words under it.
- **Death detection** without new libc bindings: `/proc/<pid>/stat` missing, or present with a
  different start time (PID reuse). Linux-only, which the arena already is.
- **Reclaimed shards keep their values.** The new owner continues incrementing from where the
  dead worker stopped, which is exactly what keeps counters monotonic across worker restarts
  (FPM `pm.max_requests`, crashes). Only cells whose type says "live only" (§5.2) are zeroed —
  by the claimer, *before* it starts writing, so they still have a single writer.
- **Exhaustion** never throws in the hot path: a process that cannot claim a shard uses the
  *overflow plane*, a shared shard whose cells are updated under a stripe mutex — correct,
  ~10–100× slower, and counted by a self-metric so it is visible instead of silent.
- A claim is per OS process. ZTS (one process, many threads) is out of scope for v1; the claim
  key would become (pid, tid).

### 4.2 Cell plane (`Ipc\CellPlane`, this repository)

A block of `shards × stride` bytes, where `stride = align64(cellsPerShard × 8)`:

```text
  shard 0  [ cell 0 | cell 1 | ... | cell n-1 | pad to 64 B ]
  shard 1  [ cell 0 | cell 1 | ... | cell n-1 | pad to 64 B ]
  ...
```

- `bindWriter(int $shard)` returns a writer whose `int64_t*` and `double*` views cover exactly
  that shard's row (bounds checked here, once). Writer verbs: `addInt`, `addFloat`, `set*`,
  `get*` on a cell index — one bounds check against `n`, one FFI element access.
- `reader()` returns a scrape-side reader: per-word loads of any shard row, `sumInt(cell)`,
  `sumFloat(cell)`, `max/min`, and row iteration for bulk aggregation.
- Cells are **typed by the consumer**, not by the plane: the same word is either an `int64`
  (bucket counts, exact) or a `double` (counter values, sums); the family schema decides.
- No public method returns `FFI\CData` (AGENTS.md §9). Views are bound from the arena's
  mapping view, which requires a small `Arena` addition (§9).

Reads are per-word by default. A bulk `memcpy` snapshot (`readBytes()` + `unpack('q*')`)
would be faster, but glibc may copy with `rep movsb`, which is only byte-atomic
architecturally — a counter going `0x00FF → 0x0100` could be read as `0x01FF` or `0x0000`,
and Prometheus reads any decrease as a counter reset. Bulk snapshots are allowed only after a
spike measures them tear-free under concurrent writes (Phase 0), exactly as correction #2
measured single-word reads.

### 4.3 Metrics registry, families and series (`shared-metrics`)

```text
  registry header     magic | format | family capacity | family count | shard table addr
                      | directory mutex | created at | flags (frozen)
  family record       name (ptr, len) | help (ptr, len) | unit (ptr, len) | type | gauge mode
  (fixed, ~32 words)  | label count | label names addr | cells per series | bounds addr, count
                      | series capacity | series count | index addr | series array addr
                      | plane addr | family mutex | options (exemplars, native schema, ...)
  series record       created at (double) | state (LIVE | TOMBSTONE) | baseline addr
  (per series)        | label count × (value ptr, value len)
  index               open addressing, 2 × series capacity entries: hash64 | series id + 1
```

- **Families are declared before the fork** (like classes, AGENTS.md §2) and the registry can
  be `freeze()`d. Post-fork registration is allowed while not frozen, under the directory
  mutex, idempotently: registering an existing name with an identical schema returns it; a
  different schema is a typed refusal naming both.
- **Label values are arena strings** written once per series and never rewritten, so series
  creation costs one bounded set of blocks and nothing leaks per update. They are stored as
  (address, length) pairs — strings as addresses, not a packed byte encoding of the tuple.
- **Series are created lazily** under the family mutex: allocate label strings, write the
  record, then publish the index entry and bump `series count` with single aligned stores.
  The family capacity is a **cardinality limit**: past it, the update goes to a reserved
  overflow series and `shared_metrics_series_limit_hits_total{family=…}` counts it — the
  application is never broken by a label explosion, and the explosion is visible.
- **Lookups never happen on the hot path twice.** A worker resolves a label tuple once
  (under the family mutex — the mutex acquire is also the memory barrier arm64 needs to see a
  fully written record), then caches the child handle in a per-process array keyed by the
  tuple. Every later `inc()` is a PHP array hit plus one cell store.
- **Removal is a tombstone plus a baseline.** `remove(labels)` cannot zero other workers'
  cells (they have other writers), so it hides the series and records the current per-cell
  sums as its baseline; re-creating the same labels revives the record, reports
  `sum − baseline` and gets a fresh `_created`. `clear()` does the same for every series.

### 4.4 Hot path, end to end

```php
$requests->labels('GET', '200')->inc();
//  1. labels(): per-process array lookup -> CounterChild (cached; resolves once per process)
//  2. inc():    $writer->addFloat($cellIndex, 1.0)  -> one bounds check, one double RMW
```

`observe()` on a classic histogram adds a binary search over a per-process copy of the bucket
bounds and touches two cells (bucket count `int64`, sum `double`). Fibers in one process share
the process's shard safely: an update never suspends between its load and its store.

### 4.5 Scrape path and its consistency rules

1. Take each family mutex just long enough to read `series count` (acquire), then read the
   immutable series records below it without locks.
2. Ask the shard table which shards are LIVE (for live-only aggregations).
3. Sum cells per series across shards with per-word loads; accumulate shard by shard, so peak
   memory is one row, not the whole plane.

Guarantees, documented and tested:

- every value is some real past value of each shard's word — never torn;
- counters are monotonic across scrapes, including across worker death and shard reuse;
- histogram `_count` is **derived** as the sum of the non-cumulative bucket counts and
  buckets are made cumulative at render time, so `_count == le="+Inf"` and bucket monotonicity
  hold on every scrape by construction. `_sum` may be one observation ahead of or behind the
  buckets (same tolerance as other clients);
- there is no cross-family snapshot — two families are read at slightly different moments, as
  in every Prometheus client.

## 5. Prometheus and OpenMetrics types

### 5.1 Overview

| Type | Cells per series per shard | Aggregation at scrape | Worker death |
|---|---|---|---|
| Counter | 1 `double` | sum | kept (monotonic) |
| Gauge | 1 `double` (+1 timestamp for `MostRecent`) | per gauge mode, §5.2 | per mode |
| Histogram (classic) | `k + 1` `int64` buckets (incl. `+Inf`) + 1 `double` sum | sum, cumulate | kept |
| GaugeHistogram | same as histogram, buckets may decrease | sum, cumulate, `_gcount`/`_gsum` | kept, or live-only |
| Summary | 1 `int64` count + 1 `double` sum, optional quantile windows (§5.4) | sum; quantiles merged | kept |
| Native histogram | zero bucket + fixed positive/negative index windows at max schema + sum + count | sum, optional schema reduction | kept |
| Info | none — labels only, value 1 | — | — |
| StateSet | 1 `int64` bitmask (≤ 64 states) | most recent | — |
| Unknown | 1 `double`, set-only | most recent | — |

Common to all: metric/label name validation (`[a-zA-Z_:][a-zA-Z0-9_:]*`, labels
`[a-zA-Z_][a-zA-Z0-9_]*`, `__` reserved, `le`/`quantile` reserved per type), the OpenMetrics
suffix rules (counter metadata without `_total`, samples with it; `unit` must be a suffix of
the name), `_created` timestamps for counters, histograms and summaries, and constant labels
declared at registration.

### 5.2 Gauges: the mode is part of the schema

A gauge is the one type whose meaning depends on how per-worker values combine, so the mode is
declared at registration (precedent: `prometheus_client` multiprocess modes):

| Mode | Per-shard cell | Scrape | Use |
|---|---|---|---|
| `LiveSum` (default) | own contribution; `inc`/`dec`/`set` touch it | sum of LIVE shards; a reclaimed shard is zeroed by its claimer | in-flight requests, open connections |
| `Sum` | same | sum of all shards, dead included | totals that must survive restarts |
| `LiveMax` / `LiveMin` / `Max` / `Min` | own value | max/min | peak memory per worker |
| `LiveAll` / `All` | own value | one sample per shard, extra `worker` label | per-worker RSS |
| `MostRecent` | value + timestamp pair, **set-only** | value of the shard with the newest timestamp | "current config version", global set-style values |

`MostRecent` stays lock-free with single writers: each shard writes its own (value, time)
pair and the scraper picks the newest. The two words can tear against each other, which only
means a scrape may pick the second-newest *real* value. `inc`/`dec` on a `MostRecent` gauge are
refused at the API level rather than silently degraded to a lock.

### 5.3 Histograms

- Bucket helpers: `linear()`, `exponential()`, `exponentialRange()`, defaults matching
  client_golang's `DefBuckets`.
- Buckets are stored **non-cumulative** so an observation touches exactly one bucket cell.
- `startTimer()`/`time(callable)` using `hrtime()`.
- `GaugeHistogram` reuses the layout and allows negative deltas.

### 5.4 Summaries

Count and sum are always available and cheap. Quantiles are the expensive part in any sharded
design, because a quantile cannot be summed. Plan:

- quantiles are **opt-in** per family, with explicit `maxAge` and `ageBuckets` (client_golang
  semantics);
- each shard keeps `ageBuckets` rotating windows of a **mergeable log-bucketed sketch**
  (DDSketch-style, configurable relative accuracy and value range); the *owning* worker rotates
  its own windows when it notices the window index moved (single writer again), and the scraper
  merges only windows younger than `maxAge` across live shards;
- the error contract is relative (e.g. ±1 %), not CKMS rank error, and the docs say so;
- registration computes the memory cost (`shards × series × windows × buckets × 8`) and refuses
  a schema over a configured budget, naming the number. Histograms remain the recommendation.

### 5.5 Native histograms

Native (sparse, exponential) histograms need a bucket set that grows with the data — the one
thing an arena structure cannot do. They are therefore stored as **fixed index windows**:

- one zero bucket, one positive and one negative window of `W` buckets at a declared maximum
  schema `S` and a declared value range; out-of-range observations land in the edge bucket and
  are counted by a self-metric;
- at scrape, shards are merged and the schema is reduced (lossless downsampling: merge pairs
  of buckets) until the bucket count fits `maxBuckets`;
- exposition is protobuf (`io.prometheus.client.MetricFamily`, delimited), optionally carrying
  classic buckets alongside for scrapers without native support.

This is the last type in the plan because it needs the protobuf encoder (§6) and the window
sizing deserves its own spike.

### 5.6 Exemplars

Opt-in per family (they multiply memory). One exemplar slot per bucket (histogram) or per
series (counter) per shard: `seq | value (double) | timestamp (double) | label length |
128-byte inline label buffer` — 128 bytes is the OpenMetrics limit on an exemplar label set,
and an inline buffer means updating an exemplar allocates nothing (an arena string per update
would leak, AGENTS.md §6). The writer is the shard owner; a scrape uses the `seq` word as a
seqlock (odd = in progress, re-read on change) and **skips** an exemplar it cannot read
consistently rather than waiting. Without fences this is sound on x86-64 (TSO) and best-effort
on arm64, which is acceptable for exemplars and only for exemplars; the reader also validates
length and UTF-8 before emitting.

## 6. Exposition

| Format | Content type | Notes |
|---|---|---|
| Prometheus text 0.0.4 | `text/plain; version=0.0.4; charset=utf-8` | default |
| OpenMetrics 1.0 text | `application/openmetrics-text; version=1.0.0; charset=utf-8` | `# UNIT`, `_created`, exemplars, `# EOF`, Info/StateSet/GaugeHistogram/Unknown |
| Protobuf | `application/vnd.google.protobuf; proto=io.prometheus.client.MetricFamily; encoding=delimited` | required for native histograms; small hand-written encoder, no dependency |

- Content negotiation on `Accept` with q-values; honour `escaping=` and quoted UTF-8 names
  (Prometheus 3 behaviour) behind an option, defaulting to legacy-safe names.
- `gzip` when `Accept-Encoding` allows it and `zlib` is loaded.
- **Float formatting is not `(string) $float`**: that uses `precision` (14 digits) and loses
  round-trip. Format with the shortest representation that round-trips (`%.15g`, verify,
  fall back to `%.17g`), plus `+Inf`, `-Inf`, `NaN`; integers from `int64` cells print
  exactly.
- Label value escaping (`\\`, `\"`, `\n`), HELP escaping, stable ordering (families by name,
  series by label tuple) so golden-file tests are deterministic.
- Output is streamed per family into a buffer; filtering by `name[]=` query parameters.

## 7. Serving the endpoint

Any process of the family can render the full picture, because the data is shared — so the
endpoint can live wherever is convenient:

1. **In-band handler**: `MetricsHandler::render(string $accept): array{contentType, body}`
   plus a PSR-15 middleware/request handler. Under FPM, `/metrics` is just one more request
   served by whichever worker picks it up.
2. **Exporter process**: a small non-blocking `stream_socket_server` loop forked from the
   parent alongside the workers (pcntl pools, Swoole/OpenSwoole managers, Workerman), with
   `/metrics` and `/-/healthy`, request size/time limits, optional basic auth and TLS through
   stream context, and the scrape timeout header honoured.
3. **Self-metrics**: `shared_metrics_scrape_duration_seconds`, series per family, limit hits,
   shards claimed/overflowed, lock recoveries, arena watermark and remaining bytes.

Deployment matrix (to be verified in Phase 0, not assumed):

| Setup | Fork family? | Notes |
|---|---|---|
| pcntl worker pool, Swoole/OpenSwoole, Workerman | yes | create arena + registry in the parent before forking |
| php-fpm with `opcache.preload` | yes, if the arena is created in preload and **recovered** in each worker | needs `Arena` recovery from its base address (§9) — today `bootShared()` takes a live `Arena` object, which an FPM request does not have |
| RoadRunner | **no** — workers are spawned with `exec`, not forked | unsupported by construction; README's "blessed setups" should be re-checked against this |
| FrankenPHP | threads in one process | needs per-thread shards (ZTS); out of scope for v1 |

## 8. Memory budget

`bytes ≈ Σ families ( shards × series_capacity × cells_per_series × 8 ) + labels + indexes`

| Family | Cells | Series | 64 shards |
|---|---:|---:|---:|
| `http_requests_total{method,status}` | 1 | 200 | 100 KB |
| `http_request_duration_seconds{route}`, 12 buckets | 14 | 1 000 | 7.2 MB |
| same with exemplars on every bucket | 14 + 13 × 20 | 1 000 | ~140 MB — why exemplars are opt-in |

Registration reports the cost, the registry refuses a schema that does not fit the arena
(typed, naming the family and the bytes), and the whole block is prefaulted at creation so RSS
is flat from the first request (AGENTS.md §6, native-php-coroutines#24).

## 9. Changes needed in this repository

All additive; none changes a registry record, so `Registry::LAYOUT_VERSION` stays at 5.

1. **`Arena` float and plane views** — a `double` view bound at map time next to the existing
   word view, `readFloat()`/`writeFloat()`, and an `@internal` way for `Ipc\CellPlane` to bind
   a bounds-checked row view from the mapping (not from a temporary holder, §2).
2. **`Arena` recovery** — rebinding an `Arena` object over an existing mapping from its base
   address (magic + layout version + size checked, `isCreator()` false), so FPM workers can
   reach an arena created in `opcache.preload`. Without it the FPM row of §7 does not work.
3. **`Ipc\ShardTable`** — claim, reap, live set, overflow plane (§4.1).
4. **`Ipc\CellPlane`** — shard-major, cache-line-aligned, prefaulted cells with bound writers
   and a scrape reader (§4.2).
5. **`Ipc\FixedIndex`** — fixed-capacity open-addressing `hash64 → id` index with
   publish-after-write, generic enough for the series index and other consumers.
6. Tests in the fork harness (`IpcTestCase`), the S18 spike promoted to a test where it makes
   a claim, `docs/shared-memory-model.md` §8 extended with the new primitives.

## 10. Phases and gates

| Phase | Scope | Gate |
|---|---|---|
| **0 — spikes** | S18 on both minors; bulk-`memcpy` tear measurement; shard reuse after SIGKILL with PID reuse; FPM + preload + arena recovery prototype; native-histogram window sizing | written verdicts in `spikes/README.md`; a decision on bulk reads |
| **1 — primitives** (this repo) | §9 items 1–6 | suite green on 8.4 and 8.5 by exit code, `MALLOC_CHECK_=3 MALLOC_PERTURB_=85`, `--order-by=random`; N workers × K increments sum exactly; SIGKILL mid-loop keeps totals and the next claimer reuses the shard |
| **2 — core metrics** (new repo) | registry, families, series, label cache, Counter, Gauge (all modes), classic Histogram; text 0.0.4; in-band handler | output passes `promtool check metrics`; histogram invariants checked on every scrape while 8 workers observe; soak: flat RSS and watermark plateau over 10⁶ requests |
| **3 — OpenMetrics** | `_created`, units, Info, StateSet, GaugeHistogram, Unknown, exemplars, negotiation, gzip, removal/baseline | OpenMetrics parser accepts every golden file; exemplar seqlock never emits a torn exemplar under a writer loop |
| **4 — advanced types** | windowed Summary quantiles, native histograms, protobuf, UTF-8 names | quantile error within the declared bound against an exact reference; Prometheus 3 ingests native histograms in an integration job |
| **5 — integration** | exporter process, PSR-15 middleware, `promphp/prometheus_client_php` storage adapter, collectors (process, PHP runtime, opcache, arena and `Ipc` stats such as `ResultSlotTable` live/recycled/retired), docs | adapter passes promphp's own storage test-suite; benchmark against promphp's APCu and Redis adapters published in the README |

The `promphp` adapter in Phase 5 is the adoption path: existing applications switch storage
without touching their instrumentation. It is slower than native child handles (it resolves
names and label arrays on every call), which the benchmark should show honestly.

## 11. API sketch (new repository)

```php
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedMetrics\{Buckets, GaugeMode, Metrics};

// parent, before the fork
$arena   = Arena::create();
$metrics = Metrics::create($arena, shards: 64, families: 128);

$requests = $metrics->counter('http_requests_total', 'HTTP requests.', ['method', 'status'],
    maxSeries: 200);
$latency  = $metrics->histogram('http_request_duration_seconds', 'Request latency.', ['route'],
    buckets: Buckets::exponential(0.005, 2, 12), maxSeries: 1_000, exemplars: false);
$inflight = $metrics->gauge('http_inflight_requests', 'Requests in flight.',
    mode: GaugeMode::LiveSum);
$build    = $metrics->info('app_build', 'Build information.', ['version', 'commit']);
$metrics->freeze();

// in a worker
$requests->labels('GET', '200')->inc();
$inflight->inc();
$timer = $latency->labels('/api/orders')->startTimer();
// ...
$timer->observeDuration();
$inflight->dec();

// /metrics, in any process of the family
[$contentType, $body] = $metrics->exposition()->render($_SERVER['HTTP_ACCEPT'] ?? '');
```

## 12. Open questions for the maintainer

1. Name of the new repository and namespace (`lisachenko/shared-metrics`,
   `Lisachenko\SharedMetrics` used above as a placeholder).
2. Which servers are the first-class targets? The answer orders Phase 0 (FPM recovery vs.
   exporter process first).
3. Is the `promphp` adapter wanted, or should the native API be the only surface?
4. Priority of native histograms and protobuf relative to Summary quantiles — both are
   expensive; either could move to a later release.
5. Default gauge mode: `LiveSum` is proposed; `prometheus_client` defaults to `All`.
