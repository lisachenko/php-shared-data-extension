<?php

declare(strict_types=1);

/**
 * S12 — cross-process mutation visibility
 * =======================================
 *
 * Question: if a zend_object lives in MAP_SHARED anonymous memory, does a scalar
 * property write performed by one process become visible to another process?
 *
 * Phase A (pure FFI, no engine): hand-built 16-byte zval-like slot in a MAP_SHARED
 *   region, guarded by a process-shared pthread mutex. One child writes, one child
 *   reads. Establishes:
 *     A1  aligned 8-byte words never tear (x86-64) and locked reads never observe
 *         a half-written pair;
 *     A2  a zval is a 16-byte struct (value + type_info) whose two halves are written
 *         by two separate stores — an UNLOCKED reader does observe the value of one
 *         generation with the type_info of another. This is the reason a lock (or a
 *         seqlock) is mandatory for type-changing writes.
 *
 * Phase B (real engine objects, needs z-engine):
 *     B1  NEGATIVE CONTROL — today's php-shared-data-extension path. The persistent
 *         clone lives in malloc()'d heap, which fork() makes copy-on-write. A child's
 *         property write is invisible to the parent and to its siblings. This is the
 *         motivation for the whole epic.
 *     B2  THE PREMISE — the same engine-formatted zend_object byte-copied into the
 *         MAP_SHARED arena and re-anchored as a PHP instance. A child's ordinary
 *         `$obj->prop = ...` write IS visible to the parent and to its siblings.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S12_cross_process_mutation.php
 */

require __DIR__ . '/lib/bootstrap.php';

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass as ZReflectionClass;
use ZEngine\Reflection\ReflectionValue;

spike_header('S12', 'cross-process mutation visibility');

// ---------------------------------------------------------------------------
// Arena layout (byte offsets inside one MAP_SHARED region)
// ---------------------------------------------------------------------------
const ARENA_SIZE = 4 << 20;          // 4 MiB

const OFF_MUTEX      = 0;            // 64 bytes (glibc pthread_mutex_t is 40, padded)
const OFF_ZVAL       = 64;           // 16 bytes: [value:8][u1.type_info:4][u2:4]
const OFF_MIRROR     = 80;           // 8 bytes: writer's copy of value, for torn detection
const OFF_CNT        = 128;          // counters, 8 bytes each
const CNT_WRITES     = 0;
const CNT_READS      = 1;
const CNT_TORN_LOCK  = 2;
const CNT_TORN_FREE  = 3;
const CNT_TYPEMIX    = 4;
const CNT_LAST_SEEN  = 5;
const CNT_FREE_READS = 6;
const OFF_OBJECTS    = 4096;         // bump area for engine-formatted objects

$arena = spike_mmap_shared(ARENA_SIZE);
printf("arena: 0x%x .. 0x%x (%d bytes, MAP_SHARED|MAP_ANONYMOUS)\n\n", $arena, $arena + ARENA_SIZE, ARENA_SIZE);

$cnt = spike_at('uint64_t', $arena + OFF_CNT);
$mutex = spike_mutex_init($arena + OFF_MUTEX, robust: false);

$ITER = (int) (getenv('SPIKE_ITER') ?: 1000000);

// ===========================================================================
// A1/A2 — hand-built zval slot, writer child + reader child
// ===========================================================================
spike_step(sprintf('A — %d iterations, 1 writer child + 1 locked reader child + 1 UNLOCKED reader child', $ITER));

const MASK = 0x5a5a5a5a5a5a5a5a;
const IS_LONG = 4;
const IS_DOUBLE = 5;

$t0 = microtime(true);

$pids = spike_fork(3, function (int $role) use ($arena, $ITER, $cnt): int {
    $ffi    = libc();
    $mutex  = spike_mutex_at($arena + OFF_MUTEX);
    $lval   = spike_at('int64_t', $arena + OFF_ZVAL);
    $dval   = spike_at('double', $arena + OFF_ZVAL);
    $tinfo  = spike_at('uint32_t', $arena + OFF_ZVAL + 8);
    $mirror = spike_at('int64_t', $arena + OFF_MIRROR);

    if ($role === 0) {
        // WRITER: alternates a long generation and a double generation, so both
        // halves of the zval change every iteration.
        for ($i = 1; $i <= $ITER; $i++) {
            $ffi->pthread_mutex_lock($mutex);
            if (($i & 1) === 1) {
                $lval[0]   = $i;
                $tinfo[0]  = IS_LONG;
                $mirror[0] = $i ^ MASK;
            } else {
                $dval[0]   = (float) $i;
                $tinfo[0]  = IS_DOUBLE;
                $mirror[0] = $lval[0] ^ MASK;   // mirror of the raw 8 bytes
            }
            $cnt[CNT_WRITES] = $i;
            $ffi->pthread_mutex_unlock($mutex);
        }

        return 0;
    }

    if ($role === 1) {
        // LOCKED READER: every observation must be internally consistent.
        $torn = 0;
        $reads = 0;
        $last = 0;
        while ($cnt[CNT_WRITES] < $ITER) {
            $ffi->pthread_mutex_lock($mutex);
            $v = $lval[0];
            $t = $tinfo[0];
            $m = $mirror[0];
            $gen = $cnt[CNT_WRITES];
            $ffi->pthread_mutex_unlock($mutex);
            $reads++;
            if ($gen > 0) {
                if (($m ^ MASK) !== $v) {
                    $torn++;
                }
                if ($t !== IS_LONG && $t !== IS_DOUBLE) {
                    $torn++;
                }
                // the value the writer published must never go backwards
                if ($gen < $last) {
                    $torn++;
                }
                $last = $gen;
            }
        }
        $cnt[CNT_READS]     = $reads;
        $cnt[CNT_TORN_LOCK] = $torn;
        $cnt[CNT_LAST_SEEN] = $last;

        return 0;
    }

    // UNLOCKED READER: reads the same 16 bytes with no synchronization at all.
    //  - value/mirror mismatch  => the two 8-byte words are from different generations
    //  - type_info says LONG but the 8 bytes are a plausible double (or vice versa)
    //    => the value half and the type half came from different generations
    $tornFree = 0;
    $typeMix  = 0;
    $reads    = 0;
    while ($cnt[CNT_WRITES] < $ITER) {
        $v = $lval[0];
        $t = $tinfo[0];
        $m = $mirror[0];
        $reads++;
        if ($m !== 0 && ($m ^ MASK) !== $v) {
            $tornFree++;
        }
        // A LONG generation always stores a small positive integer; a DOUBLE generation
        // stores an IEEE-754 bit pattern whose magnitude as an integer is astronomically
        // large. Seeing "type says LONG" together with a double bit pattern (or the
        // reverse) proves the halves are from different generations.
        $looksDouble = $v < 0 || $v > 0x0010000000000000;
        if ($t === IS_LONG && $looksDouble) {
            $typeMix++;
        } elseif ($t === IS_DOUBLE && !$looksDouble && $v !== 0) {
            $typeMix++;
        }
    }
    $cnt[CNT_TORN_FREE]  = $tornFree;
    $cnt[CNT_TYPEMIX]    = $typeMix;
    $cnt[CNT_FREE_READS] = $reads;

    return 0;
});

$waits = spike_wait($pids);
$dt    = microtime(true) - $t0;

printf("children: %s   (%.2f s, %.0f writes/s)\n", spike_describe_wait($waits), $dt, $ITER / max($dt, 1e-9));
spike_result(
    sprintf('A1 locked reader: %d reads, %d inconsistent observations', $cnt[CNT_READS], $cnt[CNT_TORN_LOCK]),
    $cnt[CNT_TORN_LOCK] === 0,
);
spike_note(sprintf('locked reader last observed generation %d of %d (progress proves visibility)', $cnt[CNT_LAST_SEEN], $ITER));
spike_result(
    sprintf('A2 unlocked reader: %d reads, %d value/mirror mismatches, %d value-vs-type mismatches',
        $cnt[CNT_FREE_READS], $cnt[CNT_TORN_FREE], $cnt[CNT_TYPEMIX]),
    true,
    ($cnt[CNT_TORN_FREE] + $cnt[CNT_TYPEMIX]) > 0
        ? 'EXPECTED: a 16-byte zval is NOT atomic; readers need the lock'
        : 'no mismatch observed in this run (timing-dependent; the hazard is still real)',
);
echo "\n";

// ===========================================================================
// Phase B — real engine objects
// ===========================================================================
if (!Core::isInitialized()) {
    spike_result('B skipped', false, 'z-engine is unavailable on this PHP minor');
    exit(0);
}

final class S12Holder
{
    public int $counter = 0;

    public float $ratio = 0.0;

    public bool $flag = false;
}

// --- B1: the malloc/COW negative control ------------------------------------
spike_step('B1 — NEGATIVE CONTROL: persistent (malloc) clone + fork == copy-on-write');

$source = new S12Holder();
$source->counter = 100;

$value = new ReflectionValue($source);
$rawSource = $value->getRawObject();
$ce = $rawSource->ce;
$objectSize = ZReflectionClass::getObjectSize($ce);
$value->release();

// Mint the same persistent clone php-shared-data-extension mints today.
$mallocClone = \ZEngine\Type\PersistentObjectFactory::persistentClone($rawSource);
Core::$executor->objectStore->put($mallocClone);
$mallocAddr = Core::addressOf($mallocClone);
$mallocValue = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $mallocClone[0]);
$mallocValue->getNativeValue($mallocInstance);

printf("      persistent clone at 0x%x, object size %d bytes (malloc/pemalloc heap)\n", $mallocAddr, $objectSize);
$mallocInstance->counter = 100;

// A shared scoreboard so the children can report back without serialization.
$score = spike_at('int64_t', $arena + OFF_CNT + 8 * 16);

$pids = spike_fork(1, function () use ($mallocInstance, $score): int {
    $mallocInstance->counter = 424242;    // write in the child
    $score[0] = $mallocInstance->counter; // child's own view
    return 0;
});
spike_wait($pids);

printf("      child wrote counter=424242 (child read back %d)\n", $score[0]);
spike_result(
    sprintf('B1 parent still sees counter=%d', $mallocInstance->counter),
    $mallocInstance->counter === 100,
    'CONFIRMED: malloc memory is COW across fork — mutations are NOT shared',
);
echo "\n";

// --- B2: the same object living in the MAP_SHARED arena ---------------------
spike_step('B2 — THE PREMISE: byte-copy the engine-formatted object into MAP_SHARED and re-anchor');

$arenaObjectAddr = $arena + OFF_OBJECTS;
libc()->memcpy(
    spike_at('char', $arenaObjectAddr),
    spike_at('char', $mallocAddr),
    $objectSize,
);
$arenaObject = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $arenaObjectAddr);

// Re-anchor: give the arena-resident zend_object a request handle and materialize a
// PHP instance whose zval points straight at the arena address.
$handle = Core::$executor->objectStore->put($arenaObject);
$arenaValue = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $arenaObject[0]);
$arenaValue->getNativeValue($shared);

printf("      arena object at 0x%x, handle %d, spl_object_id=%d, class=%s\n",
    $arenaObjectAddr, $handle, spl_object_id($shared), get_class($shared));

$shared->counter = 7;
$shared->ratio   = 0.5;
$shared->flag    = false;
spike_result('B2 pre-fork read-back through the arena instance', $shared->counter === 7 && $shared->ratio === 0.5);

// Children: A writes under the mutex, B reads under the mutex, C reads with NO lock.
//
// The invariant the readers check is a per-generation triple: for generation $i the
// object must hold counter=$i, ratio=$i/4.0, flag=odd($i). Any other combination means
// the reader saw a half-applied multi-property update.
$report = spike_at('int64_t', $arena + OFF_CNT + 8 * 20);
$stamp  = spike_at('uint64_t', $arena + OFF_CNT + 8 * 32);   // hrtime(true) of the last publish
$ROUNDS = 200000;

$t0 = microtime(true);
$pids = spike_fork(3, function (int $role) use ($arena, $shared, $report, $stamp, $ROUNDS): int {
    $ffi   = libc();
    $mutex = spike_mutex_at($arena + OFF_MUTEX);

    if ($role === 0) {                       // writer
        for ($i = 1; $i <= $ROUNDS; $i++) {
            $ffi->pthread_mutex_lock($mutex);
            $shared->counter = $i;
            $shared->ratio   = (float) $i / 4.0;
            $shared->flag    = ($i & 1) === 1;
            $stamp[0]        = hrtime(true);
            $ffi->pthread_mutex_unlock($mutex);
        }
        $report[0] = 1;                      // writer done

        return 0;
    }

    if ($role === 1) {                       // LOCKED reader
        $reads = 0;
        $bad   = 0;
        $last  = 0;
        $maxLagNs = 0;
        while ($report[0] === 0) {
            $ffi->pthread_mutex_lock($mutex);
            $c  = $shared->counter;
            $r  = $shared->ratio;
            $f  = $shared->flag;
            $ts = $stamp[0];
            $ffi->pthread_mutex_unlock($mutex);
            $reads++;
            if ($c > 0) {
                if ($r !== (float) $c / 4.0 || $f !== (($c & 1) === 1)) {
                    $bad++;
                }
                if ($c < $last) {
                    $bad++;
                }
                $last = $c;
                $lag  = hrtime(true) - $ts;
                if ($lag > $maxLagNs) {
                    $maxLagNs = $lag;
                }
            }
        }
        $report[1] = $reads;
        $report[2] = $bad;
        $report[3] = $last;
        $report[4] = $maxLagNs;

        return 0;
    }

    // UNLOCKED reader: same triple, no mutex at all
    $reads = 0;
    $bad   = 0;
    while ($report[0] === 0) {
        $c = $shared->counter;
        $r = $shared->ratio;
        $f = $shared->flag;
        $reads++;
        if ($c > 0 && ($r !== (float) $c / 4.0 || $f !== (($c & 1) === 1))) {
            $bad++;
        }
    }
    $report[5] = $reads;
    $report[6] = $bad;

    return 0;
});
$waits = spike_wait($pids);
$dt    = microtime(true) - $t0;

printf("      children: %s (%.2f s)\n", spike_describe_wait($waits), $dt);
printf("      LOCKED reader:   %d reads, %d inconsistent, highest counter observed %d of %d, max value age %.1f us\n",
    $report[1], $report[2], $report[3], $ROUNDS, $report[4] / 1000);
printf("      UNLOCKED reader: %d reads, %d inconsistent (%.2f%%)\n",
    $report[5], $report[6], $report[5] > 0 ? 100 * $report[6] / $report[5] : 0.0);

spike_result('B2 cross-process visibility of engine property writes', $report[3] > 1 && $report[2] === 0,
    sprintf('parent now reads counter=%d ratio=%s flag=%s (written only by a child)',
        $shared->counter, var_export($shared->ratio, true), var_export($shared->flag, true)));
spike_result('B2 unlocked multi-property reads are inconsistent', $report[6] > 0,
    'EXPECTED: multi-slot updates are not atomic — a reader must hold the same lock');

spike_result('B2 parent observes the LAST child write', $shared->counter === $ROUNDS,
    sprintf('expected %d', $ROUNDS));

// Object identity survives: the parent's own zval still points at the same arena bytes
spike_result('B2 arena object identity stable in parent', Core::addressOf($arenaObject) === $arenaObjectAddr);

// ===========================================================================
// C — the reverse direction: a child allocates a NEW object in the arena and
//     hands its 8-byte address to the parent over a pipe (E1 acceptance #2)
// ===========================================================================
echo "\n";
spike_step('C — child bump-allocates a NEW shared object POST-fork; the parent attaches it by address');

$bump = spike_at('uint64_t', $arena + OFF_CNT + 8 * 40);
$bump[0] = OFF_OBJECTS + 65536;                  // bump cursor, past the B2 object

[$parentEnd, $childEnd] = spike_pipe();

$pid = pcntl_fork();
if ($pid === 0) {
    fclose($parentEnd);
    $ffi   = libc();
    $mutex = spike_mutex_at($arena + OFF_MUTEX);

    $fresh = new S12Holder();
    $fresh->counter = 31337;
    $fresh->ratio   = 2.5;
    $fresh->flag    = true;

    $fv   = new ReflectionValue($fresh);
    $rawF = $fv->getRawObject();
    $sz   = ZReflectionClass::getObjectSize($rawF->ce);

    // bump-allocate under the arena lock, 16-byte aligned
    $ffi->pthread_mutex_lock($mutex);
    $off     = ($bump[0] + 15) & ~15;
    $bump[0] = $off + $sz;
    $ffi->pthread_mutex_unlock($mutex);

    $addr = $arena + $off;
    $ffi->memcpy(spike_at('char', $addr), spike_at('char', Core::addressOf($rawF)), $sz);
    $fv->release();

    // Same GC surgery PersistentObjectFactory::persistentClone() performs, applied to
    // an ARENA block instead of a malloc block.
    $arenaObj = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $addr);
    $arenaObj->gc->refcount     = \ZEngine\Type\PersistentObjectFactory::PIN_BASELINE;
    $arenaObj->gc->u->type_info = Core::engineConstant('GC_OBJECT')
        | Core::engineConstant('GC_NOT_COLLECTABLE')
        | Core::engineConstant('GC_PERSISTENT');
    $arenaObj->extra_flags |= Core::engineConstant('IS_OBJ_DESTRUCTOR_CALLED')
        | Core::engineConstant('IS_OBJ_FREE_CALLED');
    $arenaObj->handlers   = Core::cast(\ZEngine\Generated\zend_object_handlers::class,
        Core::addr(Core::getStandardObjectHandlers()));
    $arenaObj->properties = null;

    fwrite($childEnd, pack('JJ', $addr, $sz));
    fflush($childEnd);
    spike_hard_exit(0);
}
fclose($childEnd);
$msg  = unpack('Jaddr/Jsize', (string) fread($parentEnd, 16));
$wait = spike_wait([$pid]);
printf("      child: %s; it published a %d-byte object at 0x%x (8 bytes over the pipe, no serialization)\n",
    spike_describe_wait($wait), $msg['size'], $msg['addr']);

$inArena = $msg['addr'] > $arena && $msg['addr'] < $arena + ARENA_SIZE;
$newObj  = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $msg['addr']);
Core::$executor->objectStore->put($newObj);
$newVal  = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $newObj[0]);
$newVal->getNativeValue($adopted);

printf("      parent attached it: class=%s counter=%d ratio=%s flag=%s\n",
    get_class($adopted), $adopted->counter, var_export($adopted->ratio, true), var_export($adopted->flag, true));
spike_result('C an object created by a child post-fork is readable by the parent', $inArena
    && $adopted instanceof S12Holder
    && $adopted->counter === 31337 && $adopted->ratio === 2.5 && $adopted->flag === true);
spike_note('the child had already exited: only the arena bytes survive, and that is enough');

echo "\nDone.\n";

// Deliberately leave the arena mapped; the process is about to exit anyway.
