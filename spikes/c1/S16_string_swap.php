<?php

declare(strict_types=1);

/**
 * S16 — string swap visibility
 * ============================
 *
 * A shared object's string property is a zval whose value.str is an 8-byte pointer to a
 * zend_string. Mutating such a property means SWAPPING that pointer at a new arena-interned
 * string; the string bytes themselves are never edited. The questions:
 *
 *   A  can two zend_strings be interned inside the MAP_SHARED arena and read as ordinary
 *      PHP strings by every process?
 *   B  does swapping the 8-byte pointer under a mutex ever produce a torn read?
 *   C  does swapping it WITHOUT a mutex ever produce a torn read? (naturally-aligned
 *      8-byte loads/stores are atomic on x86-64 — this measures the real bound)
 *   D  control: the SAME experiment with the pointer deliberately straddling a cache-line
 *      boundary, which is where x86-64 stops being atomic.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S16_string_swap.php
 */

require __DIR__ . '/lib/bootstrap.php';

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass as ZReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;

spike_header('S16', 'string swap visibility');

if (!Core::isInitialized()) {
    spike_result('S16 skipped', false, 'z-engine is unavailable on this PHP minor');
    exit(0);
}

const ARENA_SIZE = 4 << 20;
const OFF_MUTEX  = 0;
const OFF_REPORT = 256;
const OFF_STR_A  = 1024;
const OFF_STR_B  = 1536;
const OFF_OBJ    = 4096;
const OFF_ALIGN  = 8192;      // naturally aligned 8-byte slot
const OFF_STRADD  = 12288 - 4;       // 8-byte slot straddling a 4 KiB PAGE boundary

$arena  = spike_mmap_shared(ARENA_SIZE);
$mutex  = spike_mutex_init($arena + OFF_MUTEX);
$report = spike_at('int64_t', $arena + OFF_REPORT);

// ===========================================================================
// A — intern two zend_strings inside the arena
// ===========================================================================
spike_step('A — arena-intern two zend_strings');

/**
 * Copies a persistent interned zend_string into the arena and returns its arena address.
 *
 * struct _zend_string { zend_refcounted_h gc; zend_ulong h; size_t len; char val[1]; }
 * The GC header already carries GC_STRING|GC_IMMUTABLE|IS_STR_INTERNED from
 * StringEntry::persistentInterned(), which is exactly the shape a shared string needs:
 * the engine copies it into zvals WITHOUT refcounting and copy-on-writes on mutation,
 * so no process ever tries to free it or to bump a refcount in shared memory.
 */
$internIntoArena = static function (string $value, int $offset) use ($arena): array {
    $entry  = StringEntry::persistentInterned($value);
    $raw    = $entry->getRawValue();
    $bytes  = 24 + $entry->getLength() + 1;      // gc + h + len + val[len] + NUL
    libc()->memcpy(spike_at('char', $arena + $offset), spike_at('char', Core::addressOf($raw)), $bytes);

    return [$arena + $offset, $bytes, $entry];
};

[$addrA, $sizeA, $entryA] = $internIntoArena('alpha-alpha-alpha-alpha', OFF_STR_A);
[$addrB, $sizeB, $entryB] = $internIntoArena('BRAVO-BRAVO-BRAVO-BRAVO-BRAVO-BRAVO', OFF_STR_B);

$viewA = StringEntry::fromCData(Core::pointerAtAddress(\ZEngine\Generated\zend_string::class, $addrA));
$viewB = StringEntry::fromCData(Core::pointerAtAddress(\ZEngine\Generated\zend_string::class, $addrB));

printf("      A at 0x%x (%d bytes) len=%d interned=%s value=%s\n",
    $addrA, $sizeA, $viewA->getLength(), var_export($viewA->isInterned(), true), $viewA->getStringValue());
printf("      B at 0x%x (%d bytes) len=%d interned=%s value=%s\n",
    $addrB, $sizeB, $viewB->getLength(), var_export($viewB->isInterned(), true), $viewB->getStringValue());
spike_result('A both strings readable from the arena',
    $viewA->getStringValue() === 'alpha-alpha-alpha-alpha' && $viewB->getStringValue() === 'BRAVO-BRAVO-BRAVO-BRAVO-BRAVO-BRAVO');

// ===========================================================================
// A2 — a shared object whose string property points at arena string A
// ===========================================================================
final class S16Holder
{
    public string $name = 'initial';

    public int $seq = 0;
}

$src   = new S16Holder();
$rv    = new ReflectionValue($src);
$raw   = $rv->getRawObject();
$size  = ZReflectionClass::getObjectSize($raw->ce);
$clone = PersistentObjectFactory::persistentClone($raw);
$rv->release();

libc()->memcpy(spike_at('char', $arena + OFF_OBJ), spike_at('char', Core::addressOf($clone)), $size);
$sharedObj = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $arena + OFF_OBJ);
Core::$executor->objectStore->put($sharedObj);
$objValue = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $sharedObj[0]);
$objValue->getNativeValue($shared);

// properties_table[0] is $name (declaration order). Point it at arena string A,
// non-refcounted IS_STRING (== 6) because the payload is GC_IMMUTABLE.
$slotAddr = $arena + OFF_OBJ + FFI::sizeof(Core::new('zend_object')) - FFI::sizeof(Core::new('zval'));
$slotPtr  = spike_at('uint64_t', $slotAddr);
$slotType = spike_at('uint32_t', $slotAddr + 8);
$slotPtr[0]  = $addrA;
$slotType[0] = 6;

printf("      \$name slot zval at 0x%x (value word 8-byte aligned: %s)\n",
    $slotAddr, var_export($slotAddr % 8 === 0, true));
spike_result('A2 property reads through the arena string', $shared->name === 'alpha-alpha-alpha-alpha',
    var_export($shared->name, true));

// ===========================================================================
// B/C — swap the pointer under a mutex and without one
// ===========================================================================
echo "\n";
$ROUNDS = 300000;
spike_step(sprintf('B/C — %d pointer swaps by child 0; child 1 reads LOCKED, child 2 reads UNLOCKED', $ROUNDS));

$t0   = microtime(true);
$pids = spike_fork(3, function (int $role) use ($arena, $shared, $report, $slotAddr, $addrA, $addrB, $ROUNDS): int {
    $ffi   = libc();
    $mutex = spike_mutex_at($arena + OFF_MUTEX);
    $slot  = spike_at('uint64_t', $slotAddr);
    $stamp = spike_at('uint64_t', $arena + OFF_REPORT + 8 * 60);

    if ($role === 0) {                                  // swapper
        for ($i = 1; $i <= $ROUNDS; $i++) {
            $ffi->pthread_mutex_lock($mutex);
            $slot[0] = ($i & 1) === 1 ? $addrB : $addrA;
            $stamp[0] = hrtime(true);
            $ffi->pthread_mutex_unlock($mutex);
        }
        $report[0] = 1;

        return 0;
    }

    if ($role === 1) {                                  // locked reader
        $reads = 0;
        $torn  = 0;
        $sawA  = 0;
        $sawB  = 0;
        $maxLag = 0;
        while ($report[0] === 0) {
            $ffi->pthread_mutex_lock($mutex);
            $p    = $slot[0];
            $name = $shared->name;                       // full PHP-level string read
            $ts   = $stamp[0];
            $ffi->pthread_mutex_unlock($mutex);
            $reads++;
            if ($p === $addrA) {
                $sawA++;
                if ($name !== 'alpha-alpha-alpha-alpha') {
                    $torn++;
                }
            } elseif ($p === $addrB) {
                $sawB++;
                if ($name !== 'BRAVO-BRAVO-BRAVO-BRAVO-BRAVO-BRAVO') {
                    $torn++;
                }
            } else {
                $torn++;                                  // a value that is neither: TORN
            }
            $lag = hrtime(true) - $ts;
            if ($ts > 0 && $lag > $maxLag) {
                $maxLag = $lag;
            }
        }
        $report[1] = $reads;
        $report[2] = $torn;
        $report[3] = $sawA;
        $report[4] = $sawB;
        $report[5] = $maxLag;

        return 0;
    }

    // unlocked reader: no mutex at all
    $reads  = 0;
    $torn   = 0;
    $bad    = 0;
    $maxLag = 0;
    while ($report[0] === 0) {
        $p    = $slot[0];
        $name = $shared->name;
        $ts   = $stamp[0];
        $reads++;
        if ($p !== $addrA && $p !== $addrB) {
            $torn++;                                      // a torn 8-byte pointer read
        }
        if ($name !== 'alpha-alpha-alpha-alpha' && $name !== 'BRAVO-BRAVO-BRAVO-BRAVO-BRAVO-BRAVO') {
            $bad++;                                       // a string that is neither
        }
        $lag = hrtime(true) - $ts;
        if ($ts > 0 && $lag > $maxLag) {
            $maxLag = $lag;
        }
    }
    $report[6]  = $reads;
    $report[7]  = $torn;
    $report[8]  = $bad;
    $report[9]  = $maxLag;

    return 0;
});
$waits = spike_wait($pids);
$dt    = microtime(true) - $t0;

printf("      children: %s (%.2f s)\n", spike_describe_wait($waits), $dt);
printf("      LOCKED   reader: %d reads (A=%d B=%d), %d torn, max staleness %.1f us\n",
    $report[1], $report[3], $report[4], $report[2], $report[5] / 1000);
printf("      UNLOCKED reader: %d reads, %d torn pointers, %d unexpected string values, max staleness %.1f us\n",
    $report[6], $report[7], $report[8], $report[9] / 1000);

spike_result('B locked pointer swap: never torn, both values observed',
    $report[2] === 0 && $report[3] > 0 && $report[4] > 0);
spike_result('C UNLOCKED aligned 8-byte pointer swap: never torn either',
    $report[7] === 0 && $report[8] === 0,
    'aligned 8-byte loads/stores are atomic on x86-64 — the lock buys ORDERING between slots, not per-pointer atomicity');
printf("      parent reads \$shared->name = %s\n", var_export($shared->name, true));

// ===========================================================================
// D — control: the same swap on a MISALIGNED slot that straddles a cache line
// ===========================================================================
echo "\n";
spike_step('D — control: the same 8-byte swap on a slot straddling a 4 KiB page boundary');

$straddleAddr = $arena + OFF_STRADD;
printf("      slot at 0x%x: offset %% 4096 = %d, offset %% 64 = %d — the 8 bytes span two pages\n",
    $straddleAddr, $straddleAddr % 4096, $straddleAddr % 64);

$D_ROUNDS = 3000000;
$pids = spike_fork(2, function (int $role) use ($arena, $report, $straddleAddr, $addrA, $addrB, $D_ROUNDS): int {
    $slot = spike_at('uint64_t', $straddleAddr);

    if ($role === 0) {
        for ($i = 1; $i <= $D_ROUNDS; $i++) {
            $slot[0] = ($i & 1) === 1 ? $addrB : $addrA;
        }
        $report[20] = 1;

        return 0;
    }

    $reads = 0;
    $torn  = 0;
    while ($report[20] === 0) {
        $p = $slot[0];
        $reads++;
        if ($p !== 0 && $p !== $addrA && $p !== $addrB) {
            $torn++;
        }
    }
    $report[21] = $reads;
    $report[22] = $torn;

    return 0;
});
$waits = spike_wait($pids);
printf("      children: %s\n", spike_describe_wait($waits));
printf("      misaligned unlocked reader: %d reads, %d TORN values\n", $report[21], $report[22]);
spike_result('D misaligned (page-straddling) unlocked reads', true,
    $report[22] > 0
        ? sprintf('TORE %d times: the atomicity guarantee is alignment-dependent, arena zvals must stay 8-byte aligned', $report[22])
        : 'no tearing observed on this CPU, but the ISA gives no guarantee for a misaligned access — keep the alignment invariant');

echo "\nDone.\n";
