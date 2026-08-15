<?php

declare(strict_types=1);

/**
 * S8 / S15 — fast sanity confirmations (X1 owns the in-repo versions)
 * ===================================================================
 *
 * S8  robust process-shared mutex, owner-died recovery:
 *       a child is SIGKILLed while holding the lock; the next pthread_mutex_lock()
 *       in another process must return EOWNERDEAD (130) rather than hang forever,
 *       and pthread_mutex_consistent() must make the mutex usable again.
 *       Control: skipping consistent() poisons the mutex with ENOTRECOVERABLE (131).
 *
 * S15 bump allocation race: 4 children carve blocks out of one shared arena through
 *       a single bump pointer held under the mutex. No two allocations may overlap.
 *       Control: the identical run WITHOUT the mutex, to show the race is real.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S08_S15_mutex_and_bump.php
 */

require __DIR__ . '/lib/bootstrap.php';

spike_header('S8/S15', 'robust mutex recovery + bump-allocation race');

const ARENA_SIZE = 32 << 20;

const OFF_MUTEX_R1 = 0;       // robust mutex #1 (recovered)
const OFF_MUTEX_R2 = 64;      // robust mutex #2 (deliberately NOT recovered)
const OFF_MUTEX_B  = 128;     // bump allocator mutex
const OFF_FLAGS    = 256;
const OFF_BUMP     = 320;     // uint64 bump pointer (offset into the arena)
const OFF_RECN     = 328;     // uint64 record counter
const OFF_RECS     = 4096;    // records: [offset, size, tag] * int64
const OFF_HEAP     = 8 << 20; // where carved blocks live (well past the record table)

$arena = spike_mmap_shared(ARENA_SIZE);
$ffi   = libc();
$flags = spike_at('int64_t', $arena + OFF_FLAGS);
$bump  = spike_at('uint64_t', $arena + OFF_BUMP);
$recN  = spike_at('uint64_t', $arena + OFF_RECN);
$recs  = spike_at('int64_t', $arena + OFF_RECS);

spike_mutex_init($arena + OFF_MUTEX_B, robust: true);   // the bump allocator's lock

// ===========================================================================
// S8 — robust mutex, owner died
// ===========================================================================
spike_step('S8a — child SIGKILLed while holding a ROBUST process-shared mutex');

$m1 = spike_mutex_init($arena + OFF_MUTEX_R1, robust: true);

$pid = pcntl_fork();
if ($pid === 0) {
    $m = spike_mutex_at($arena + OFF_MUTEX_R1);
    libc()->pthread_mutex_lock($m);
    $flags[0] = 1;                                  // "I hold the lock"
    posix_kill(posix_getpid(), SIGKILL);            // die holding it
    spike_hard_exit(1);
}
while ($flags[0] === 0) {
    usleep(1000);
}
$waits = spike_wait([$pid]);
printf("      holder: %s\n", spike_describe_wait($waits));

$t0 = hrtime(true);
$rc = $ffi->pthread_mutex_lock($m1);
$lockNs = hrtime(true) - $t0;
printf("      parent pthread_mutex_lock() returned %d after %.1f us  (EOWNERDEAD == %d)\n",
    $rc, $lockNs / 1000, EOWNERDEAD);
spike_result('S8a lock on an orphaned robust mutex returns EOWNERDEAD (no deadlock)', $rc === EOWNERDEAD);

$rcC = $ffi->pthread_mutex_consistent($m1);
$rcU = $ffi->pthread_mutex_unlock($m1);
$rc2 = $ffi->pthread_mutex_lock($m1);
$rcU2 = $ffi->pthread_mutex_unlock($m1);
printf("      consistent()=%d unlock()=%d then lock()=%d unlock()=%d\n", $rcC, $rcU, $rc2, $rcU2);
spike_result('S8a pthread_mutex_consistent() restores the mutex', $rcC === 0 && $rc2 === 0);

// --- control: what happens if consistent() is NOT called -------------------
spike_step('S8b — CONTROL: recover the EOWNERDEAD without calling consistent()');

$m2 = spike_mutex_init($arena + OFF_MUTEX_R2, robust: true);
$flags[1] = 0;
$pid = pcntl_fork();
if ($pid === 0) {
    libc()->pthread_mutex_lock(spike_mutex_at($arena + OFF_MUTEX_R2));
    $flags[1] = 1;
    posix_kill(posix_getpid(), SIGKILL);
    spike_hard_exit(1);
}
while ($flags[1] === 0) {
    usleep(1000);
}
spike_wait([$pid]);

$rc = $ffi->pthread_mutex_lock($m2);
$ffi->pthread_mutex_unlock($m2);              // unlock WITHOUT consistent()
$rcAfter = $ffi->pthread_mutex_lock($m2);
printf("      first lock() = %d, unlock without consistent(), next lock() = %d  (ENOTRECOVERABLE == %d)\n",
    $rc, $rcAfter, ENOTRECOVERABLE);
spike_result('S8b skipping consistent() poisons the mutex permanently', $rcAfter === ENOTRECOVERABLE,
    'the recovery handler is MANDATORY — a missed consistent() takes the whole arena down');

// --- non-robust control ----------------------------------------------------
spike_step('S8c — CONTROL: a NON-robust pshared mutex whose owner dies');

$m3addr = $arena + 192;
$m3 = spike_mutex_init($m3addr, robust: false);
$flags[2] = 0;
$pid = pcntl_fork();
if ($pid === 0) {
    libc()->pthread_mutex_lock(spike_mutex_at($m3addr));
    $flags[2] = 1;
    posix_kill(posix_getpid(), SIGKILL);
    spike_hard_exit(1);
}
while ($flags[2] === 0) {
    usleep(1000);
}
spike_wait([$pid]);

// trylock instead of lock: a non-robust orphaned mutex would block FOREVER
$rc = $ffi->pthread_mutex_trylock($m3);
printf("      pthread_mutex_trylock() on the orphaned non-robust mutex = %d (EBUSY == %d)\n", $rc, EBUSY);
spike_result('S8c a NON-robust pshared mutex is permanently stuck after an owner dies', $rc === EBUSY,
    'lock() here would block forever — PTHREAD_MUTEX_ROBUST is not optional for a multi-process arena');

// ===========================================================================
// S15 — bump allocation under the mutex
// ===========================================================================
echo "\n";
const CHILDREN  = 4;
const PER_CHILD = 25000;
const MAX_RECS  = CHILDREN * PER_CHILD;

/**
 * @param bool $useMutex whether the bump pointer is carved under the lock
 */
$runBump = static function (bool $useMutex) use ($arena, $bump, $recN, $recs, $flags): array {
    $bump[0] = OFF_HEAP;
    $recN[0] = 0;
    libc()->memset(spike_at('char', $arena + OFF_RECS), 0, MAX_RECS * 3 * 8);

    $pids = spike_fork(CHILDREN, function (int $role) use ($arena, $bump, $recN, $recs, $useMutex): int {
        $ffi   = libc();
        $mutex = spike_mutex_at($arena + OFF_MUTEX_B);
        $tag   = $role + 1;

        for ($i = 0; $i < PER_CHILD; $i++) {
            $size = 16 + (($i * 48 + $role * 16) % 208);   // 16..224, always 16-aligned
            $size = ($size + 15) & ~15;

            if ($useMutex) {
                $ffi->pthread_mutex_lock($mutex);
            }
            // Read-modify-write with a deliberately widened window, identical in both
            // modes so the comparison is fair: the mutex is the ONLY difference.
            $offset = $bump[0];
            $slot   = $recN[0];
            $next   = $offset + $size;
            for ($w = 0; $w < 4; $w++) {
                $next |= 0;
            }
            $bump[0] = $next;
            $recN[0] = $slot + 1;
            if ($useMutex) {
                $ffi->pthread_mutex_unlock($mutex);
            }

            $recs[$slot * 3 + 0] = $offset;
            $recs[$slot * 3 + 1] = $size;
            $recs[$slot * 3 + 2] = $tag;

            // Stamp the block with this child's tag: an overlap shows up as a
            // block containing somebody else's byte.
            $ffi->memset(spike_at('char', $arena + $offset), $tag, $size);
        }

        return 0;
    });

    return [spike_wait($pids), (int) $bump[0], (int) $recN[0]];
};

$verify = static function (int $records) use ($arena, $recs): array {
    // 1. overlap check by sorting the intervals
    $intervals = [];
    for ($i = 0; $i < $records; $i++) {
        $intervals[] = [$recs[$i * 3], $recs[$i * 3 + 1], $recs[$i * 3 + 2]];
    }
    usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    $overlaps = 0;
    $prevEnd  = 0;
    $duplicateOffsets = 0;
    $prevStart = -1;
    foreach ($intervals as [$off, $size, $tag]) {
        if ($off === $prevStart) {
            $duplicateOffsets++;
        }
        if ($off < $prevEnd) {
            $overlaps++;
        }
        $prevEnd   = max($prevEnd, $off + $size);
        $prevStart = $off;
    }

    // 2. content check: every byte of a block must carry its own tag
    $corrupt = 0;
    foreach ($intervals as [$off, $size, $tag]) {
        $bytes = FFI::string(spike_at('char', $arena + $off), $size);
        if ($bytes !== str_repeat(chr($tag), $size)) {
            $corrupt++;
        }
    }

    return [$overlaps, $duplicateOffsets, $corrupt];
};

spike_step(sprintf('S15a — %d children, %d bump allocations each, UNDER the mutex', CHILDREN, PER_CHILD));
$t0 = microtime(true);
[$waits, $endBump, $records] = $runBump(true);
$dt = microtime(true) - $t0;
printf("      children: %s (%.2f s)\n", spike_describe_wait($waits), $dt);
[$ov, $dup, $bad] = $verify($records);
printf("      %d records, %d bytes carved (bump %d -> %d), %d overlaps, %d duplicate offsets, %d corrupted blocks\n",
    $records, $endBump - OFF_HEAP, OFF_HEAP, $endBump, $ov, $dup, $bad);
spike_result('S15a locked bump allocation: no overlaps, no duplicate offsets, no corruption',
    $records === MAX_RECS && $ov === 0 && $dup === 0 && $bad === 0);

spike_step('S15b — CONTROL: the identical run with NO mutex (up to 3 attempts; a race is probabilistic)');
$raced = false;
for ($attempt = 1; $attempt <= 3 && !$raced; $attempt++) {
    $t0 = microtime(true);
    [$waits, $endBump, $records] = $runBump(false);
    $dt = microtime(true) - $t0;
    [$ov, $dup, $bad] = $verify($records);
    $raced = $records !== MAX_RECS || $ov > 0 || $dup > 0 || $bad > 0;
    printf("      attempt %d (%.2f s): %d records (expected %d), %d bytes carved, %d overlaps, %d duplicate offsets, %d corrupted blocks\n",
        $attempt, $dt, $records, MAX_RECS, $endBump - OFF_HEAP, $ov, $dup, $bad);
}
spike_result('S15b unlocked bump allocation races (lost updates and overlapping blocks)', $raced,
    $raced
        ? 'the mutex in S15a is load-bearing, not decoration'
        : 'no race surfaced in 3 attempts on this machine — the hazard is still real, it is just timing-dependent');

echo "\nDone.\n";
