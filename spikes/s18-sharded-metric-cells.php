<?php

declare(strict_types=1);

/**
 * Shared data PHP extension
 *
 * @copyright Copyright 2021, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * S18 - what a metric increment costs, and whether per-worker cells lose updates
 *
 * Evidence for docs/prometheus-metrics-plan.md. Four ways to bump a shared 64-bit cell are
 * timed in one process, then the two candidate cross-process layouts are run in real forks:
 *
 *  1. Ipc\AtomicInt::add()             - stripe mutex around read + write (today's RMW)
 *  2. Arena::readWord()/writeWord()    - checked word access, no lock
 *  3. a bound int64_t view             - what a per-process cell plane would hold
 *  4. a bound double view              - same, for float-valued cells (sums, counters)
 *
 *  5. N workers, ONE cell each, no lock  -> must equal N x K exactly (single writer per word)
 *  6. N workers, ONE shared cell, no lock -> loses updates; the reason RMW is never unlocked
 *  7. contended AtomicInt::add() and per-worker cells 8 vs 64 bytes apart (false sharing)
 *
 * Run from the repository root:
 *
 *   php -d ffi.enable=1 -d opcache.jit=off spikes/s18-sharded-metric-cells.php
 *
 * A finding worth keeping: the bound view below is cast out of a holder array, and the cast
 * keeps POINTING INTO that holder. Let the holder go out of scope and the view writes into
 * freed request memory - no error, the shared cell simply never changes. A real cell plane
 * must derive its views from the arena's own mapping view, never from a temporary.
 */

use Lisachenko\SharedData\Ipc\AtomicInt;
use Lisachenko\SharedData\Shm\Arena;

require __DIR__ . '/../vendor/autoload.php';

const ITERATIONS = 1_000_000;
const WORKERS    = 4;
const PER_WORKER = 300_000;

/**
 * @return array{0: FFI\CData, 1: FFI\CData} the holder (keep it alive!) and the view
 */
function boundView(int $address, string $type): array
{
    $holder    = FFI::new('uint64_t[1]');
    $holder[0] = $address;

    return [$holder, FFI::cast($type . ' *', FFI::cast('void **', FFI::addr($holder))[0])];
}

/**
 * Forks $workers children running $body($index) and returns the wall time in milliseconds
 */
function inWorkers(int $workers, callable $body): float
{
    $start = hrtime(true);
    for ($worker = 0; $worker < $workers; $worker++) {
        if (pcntl_fork() === 0) {
            $body($worker);
            exit(0);
        }
    }
    while (pcntl_wait($status) > 0) {
    }

    return (hrtime(true) - $start) / 1e6;
}

$arena = Arena::create(16 << 20);

$atomic = AtomicInt::create($arena);
$start  = hrtime(true);
for ($i = 0; $i < ITERATIONS; $i++) {
    $atomic->add(1);
}
$mutexNs = (hrtime(true) - $start) / ITERATIONS;

$cell  = $arena->allocate(8, 64);
$start = hrtime(true);
for ($i = 0; $i < ITERATIONS; $i++) {
    $arena->writeWord($cell, $arena->readWord($cell) + 1);
}
$checkedNs = (hrtime(true) - $start) / ITERATIONS;

[$intHolder, $intView] = boundView($cell, 'int64_t');
$start                 = hrtime(true);
for ($i = 0; $i < ITERATIONS; $i++) {
    $intView[0] = $intView[0] + 1;
}
$intNs = (hrtime(true) - $start) / ITERATIONS;

[$floatHolder, $floatView] = boundView($arena->allocate(8, 64), 'double');
$start                     = hrtime(true);
for ($i = 0; $i < ITERATIONS; $i++) {
    $floatView[0] += 0.5;
}
$floatNs = (hrtime(true) - $start) / ITERATIONS;

printf("uncontended, ns per increment\n");
printf("  AtomicInt::add (stripe mutex)   %7.1f\n", $mutexNs);
printf("  Arena::readWord + writeWord     %7.1f\n", $checkedNs);
printf("  bound int64_t view              %7.1f\n", $intNs);
printf("  bound double view               %7.1f\n\n", $floatNs);

$plane = $arena->allocate(WORKERS * 64, 64);
inWorkers(WORKERS, static function (int $worker) use ($plane): void {
    [$holder, $view] = boundView($plane + $worker * 64, 'int64_t');
    for ($i = 0; $i < PER_WORKER; $i++) {
        $view[0] = $view[0] + 1;
    }
});
$sum = 0;
for ($worker = 0; $worker < WORKERS; $worker++) {
    $sum += $arena->readWord($plane + $worker * 64);
}
printf("one cell per worker, unlocked:  expected %d, got %d\n", WORKERS * PER_WORKER, $sum);

$shared = $arena->allocate(8, 64);
inWorkers(WORKERS, static function () use ($arena, $shared): void {
    for ($i = 0; $i < PER_WORKER; $i++) {
        $arena->writeWord($shared, $arena->readWord($shared) + 1);
    }
});
printf(
    "one shared cell, unlocked:      expected %d, got %d (lost updates)\n\n",
    WORKERS * PER_WORKER,
    $arena->readWord($shared),
);

$contended = AtomicInt::create($arena);
$elapsed   = inWorkers(WORKERS, static function () use ($contended): void {
    for ($i = 0; $i < PER_WORKER; $i++) {
        $contended->add(1);
    }
});
printf("%d workers, wall time per increment per worker (includes fork)\n", WORKERS);
printf("  contended AtomicInt::add        %7.0f ns\n", $elapsed * 1e6 / PER_WORKER);

foreach ([8 => 'per-worker cells 8 bytes apart', 64 => 'per-worker cells 64 bytes apart'] as $stride => $label) {
    $base    = $arena->allocate(WORKERS * 64, 64);
    $elapsed = inWorkers(WORKERS, static function (int $worker) use ($base, $stride): void {
        [$holder, $view] = boundView($base + $worker * $stride, 'int64_t');
        for ($i = 0; $i < PER_WORKER; $i++) {
            $view[0] = $view[0] + 1;
        }
    });
    printf("  %-31s %7.0f ns\n", $label, $elapsed * 1e6 / PER_WORKER);
}
