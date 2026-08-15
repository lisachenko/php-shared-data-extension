<?php

declare(strict_types=1);

/**
 * S13 — pre-sized arData in shared memory
 * =======================================
 *
 * Question: can a zend_array whose STRUCT and whose arData block both live in a
 * MAP_SHARED region be read (count/foreach/lookup) and value-mutated by forked
 * children, and what exactly happens when someone makes the table grow?
 *
 * Layout mirrored from z-engine's Type/HashTable.php and include/<v>/linux-x64-nts/engine.h:
 *
 *   struct _zend_array {                        // 56 bytes
 *       zend_refcounted_h gc;                   // +0
 *       union { ... } u;                        // +8   (flags)
 *       uint32_t nTableMask;                    // +12
 *       union { uint32_t *arHash; Bucket *arData; zval *arPacked; };  // +16
 *       uint32_t nNumUsed;                      // +24
 *       uint32_t nNumOfElements;                // +28
 *       uint32_t nTableSize;                    // +32
 *       uint32_t nInternalPointer;              // +36
 *       zend_long nNextFreeElement;             // +40
 *       dtor_func_t pDestructor;                // +48
 *   };
 *   typedef struct _Bucket { zval val; zend_ulong h; zend_string *key; } Bucket;  // 32 bytes
 *
 * The data block the engine allocates is ONE allocation:
 *     [ hash slots: HT_HASH_SIZE(nTableMask) bytes ][ Bucket arData[nTableSize] ]
 * with HT_HASH_SIZE(mask) == (uint32_t)(-(int32_t)mask) * sizeof(uint32_t) and
 * HT_GET_DATA_ADDR(ht) == (char*)ht->arData - HT_HASH_SIZE(ht->nTableMask).
 * Relocating a table therefore means moving that one block and re-pointing arData.
 *
 * Steps:
 *   A  build + seal a table with N entries, relocate struct AND data block into the arena
 *   B  pre-fork sanity: count/foreach/lookup through a real PHP array zval
 *   C  post-fork: three children read it concurrently (foreach, count, lookup)
 *   D  in-place bucket VALUE overwrite by one child, observed by another, under a mutex
 *   E  THE TRAP: make the table grow. arData is replaced by a pointer into the growing
 *      process's PRIVATE heap, and because the STRUCT is shared, every other process
 *      immediately follows that dangling pointer.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S13_shared_ardata.php
 */

require __DIR__ . '/lib/bootstrap.php';

use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;

spike_header('S13', 'pre-sized arData in shared memory');

if (!Core::isInitialized()) {
    spike_result('S13 skipped', false, 'z-engine is unavailable on this PHP minor');
    exit(0);
}

const ARENA_SIZE  = 4 << 20;
const OFF_MUTEX   = 0;
const OFF_REPORT  = 256;      // int64 scoreboard
const OFF_HT      = 1024;     // zend_array struct
const OFF_HTDATA  = 4096;     // relocated data block
const SIZEOF_BUCKET = 32;

$arena  = spike_mmap_shared(ARENA_SIZE);
$mutex  = spike_mutex_init($arena + OFF_MUTEX);
$report = spike_at('int64_t', $arena + OFF_REPORT);

printf("arena 0x%x, sizeof(zend_array)=%d, sizeof(Bucket)=%d, sizeof(zval)=%d\n\n",
    $arena,
    FFI::sizeof(Core::new('HashTable')),
    FFI::sizeof(Core::new('Bucket')),
    FFI::sizeof(Core::new('zval')));

// ===========================================================================
// A — build, seal, relocate
// ===========================================================================
const N = 64;

spike_step(sprintf('A — build a %d-entry persistent table and relocate it into the arena', N));

$table = new PersistentHashTable();
for ($i = 0; $i < N; $i++) {
    $v = ReflectionValue::newEntry(ReflectionValue::IS_LONG, Core::new('zval'), true);
    $v->setNativeValue($i * 10);
    $table->add('k' . $i, $v);
    $v->release();
}
$table->markImmutable();

$raw = (new ReflectionProperty(HashTable::class, 'pointer'))->getValue($table);

/** Signed reading of the uint32 nTableMask field. */
$signedMask = static function (int $mask32): int {
    return $mask32 >= 0x80000000 ? $mask32 - 0x100000000 : $mask32;
};

$flags      = $raw->u->flags;
$isPacked   = ($flags & 4) !== 0;                 // HASH_FLAG_PACKED
$mask       = $signedMask($raw->nTableMask);
$hashSize   = (-$mask) * 4;
$tableSize  = $raw->nTableSize;
$dataSize   = $tableSize * SIZEOF_BUCKET;
$arDataAddr = Core::addressOf($raw->arData);
$blockAddr  = $arDataAddr - $hashSize;
$blockSize  = $hashSize + $dataSize;
$structSize = FFI::sizeof(Core::new('HashTable'));

printf("      source table: flags=0x%02x packed=%s nTableSize=%d nNumUsed=%d nNumOfElements=%d\n",
    $flags, var_export($isPacked, true), $tableSize, $raw->nNumUsed, $raw->nNumOfElements);
printf("      nTableMask=%d  HT_HASH_SIZE=%d  HT_DATA_SIZE=%d  one block of %d bytes at 0x%x\n",
    $mask, $hashSize, $dataSize, $blockSize, $blockAddr);

if ($isPacked) {
    spike_result('A relocation', false, 'packed table: this spike deliberately targets the hash layout');
    exit(1);
}

// Move the ONE data block, then the struct, then re-point arData.
libc()->memcpy(spike_at('char', $arena + OFF_HTDATA), spike_at('char', $blockAddr), $blockSize);
libc()->memcpy(spike_at('char', $arena + OFF_HT), spike_at('char', Core::addressOf($raw)), $structSize);

$sharedHt = Core::pointerAtAddress(\ZEngine\Generated\HashTable::class, $arena + OFF_HT);
$sharedHt->arData = Core::pointerAtAddress(\ZEngine\Generated\Bucket::class, $arena + OFF_HTDATA + $hashSize);

$sharedArDataAddr = Core::addressOf($sharedHt->arData);
printf("      arena table: struct at 0x%x, block at 0x%x, arData at 0x%x (inside arena: %s)\n",
    $arena + OFF_HT, $arena + OFF_HTDATA, $sharedArDataAddr,
    var_export($sharedArDataAddr > $arena && $sharedArDataAddr < $arena + ARENA_SIZE, true));

// NOTE: the string KEYS still point at persistent interned strings in malloc memory.
// They are read-only and COW-shared across fork, so lookups work — but they would NOT
// survive a fresh process. S16 covers moving strings into the arena.
spike_note('bucket KEYS still point at malloc-interned strings (COW-shared, fine across fork; see S16)');

// ===========================================================================
// B — pre-fork sanity through a real PHP array zval
// ===========================================================================
spike_step('B — materialize a PHP array zval pointing at the arena table');

$sharedValue = ReflectionValue::newEntry(ReflectionValue::IS_ARRAY, $sharedHt[0]);
printf("      zval type_info = 0x%x (GC_IMMUTABLE => non-refcounted IS_ARRAY = 0x7)\n",
    (new ReflectionProperty(ReflectionValue::class, 'pointer'))->getValue($sharedValue)->u1->type_info);
$sharedValue->getNativeValue($sharedArray);

spike_result('B count()', count($sharedArray) === N, 'got ' . count($sharedArray));
spike_result('B lookup k7', ($sharedArray['k7'] ?? null) === 70, var_export($sharedArray['k7'] ?? null, true));
spike_result('B array_sum over foreach', array_sum($sharedArray) === (int) (N * (N - 1) / 2 * 10),
    'sum=' . array_sum($sharedArray));

$wrapper = HashTable::fromCData($sharedHt);
spike_result('B z-engine HashTable view count', count($wrapper) === N, 'got ' . count($wrapper));

// ===========================================================================
// C + D — concurrent readers, in-place bucket value overwrite
// ===========================================================================
spike_step('C/D — 1 mutator child + 2 reader children, in-place scalar bucket overwrite under mutex');

// Address of the zval INSIDE the bucket for key 'k7' (bucket index == insertion order
// for a table that never had a delete).
$targetIndex = 7;
$targetZval  = $sharedArDataAddr + $targetIndex * SIZEOF_BUCKET;   // Bucket.val is at offset 0
printf("      bucket[%d].val zval at 0x%x\n", $targetIndex, $targetZval);

$ROUNDS = 200000;

$t0   = microtime(true);
$pids = spike_fork(3, function (int $role) use ($arena, $sharedArray, $report, $targetZval, $ROUNDS): int {
    $ffi   = libc();
    $mutex = spike_mutex_at($arena + OFF_MUTEX);
    $lval  = spike_at('int64_t', $targetZval);
    $tinfo = spike_at('uint32_t', $targetZval + 8);

    if ($role === 0) {                       // in-place scalar overwrite
        for ($i = 1; $i <= $ROUNDS; $i++) {
            $ffi->pthread_mutex_lock($mutex);
            $lval[0]  = $i;
            $tinfo[0] = 4;                   // IS_LONG, stays scalar => no refcount work
            $ffi->pthread_mutex_unlock($mutex);
        }
        $report[0] = 1;

        return 0;
    }

    if ($role === 1) {                       // reader: value of the mutated key
        $reads = 0;
        $bad   = 0;
        $last  = 0;
        while ($report[0] === 0) {
            $ffi->pthread_mutex_lock($mutex);
            $v = $sharedArray['k7'];
            $ffi->pthread_mutex_unlock($mutex);
            $reads++;
            if (!is_int($v) || $v < $last) {
                $bad++;
            }
            $last = $v;
        }
        $report[1] = $reads;
        $report[2] = $bad;
        $report[3] = $last;

        return 0;
    }

    // structural reader: full foreach + count while the other child mutates
    $walks = 0;
    $bad   = 0;
    while ($report[0] === 0) {
        $n   = 0;
        $keys = 0;
        foreach ($sharedArray as $k => $v) {
            $n++;
            if (is_string($k) && str_starts_with($k, 'k')) {
                $keys++;
            }
        }
        if ($n !== N || $keys !== N || count($sharedArray) !== N) {
            $bad++;
        }
        $walks++;
    }
    $report[4] = $walks;
    $report[5] = $bad;

    return 0;
});
$waits = spike_wait($pids);
$dt    = microtime(true) - $t0;

printf("      children: %s (%.2f s)\n", spike_describe_wait($waits), $dt);
printf("      value reader:      %d locked reads, %d anomalies, highest %d of %d\n",
    $report[1], $report[2], $report[3], $ROUNDS);
printf("      structural reader: %d full foreach+count walks, %d anomalies\n", $report[4], $report[5]);

spike_result('C concurrent foreach/count over a shared-memory table',
    $report[4] > 0 && $report[5] === 0);
spike_result('D in-place scalar bucket overwrite is visible cross-process',
    $report[3] > 1 && $report[2] === 0);
spike_result('D parent observes the last child write', $sharedArray['k7'] === $ROUNDS,
    sprintf('parent reads k7=%s (expected %d)', var_export($sharedArray['k7'], true), $ROUNDS));

// ===========================================================================
// E — THE TRAP: growth relocates arData out of the arena
// ===========================================================================
echo "\n";
spike_step('E — THE TRAP: what happens when the table has to grow');

// A separate arena so a heap-corrupting free() cannot scribble on the tables above.
$arena2 = spike_mmap_shared(1 << 20);
$report2 = spike_at('int64_t', $arena2 + 128);

$small = new PersistentHashTable();
for ($i = 0; $i < 6; $i++) {                     // HT_MIN_SIZE is 8 => 6 fits, 9 does not
    $v = ReflectionValue::newEntry(ReflectionValue::IS_LONG, Core::new('zval'), true);
    $v->setNativeValue($i);
    $small->add('s' . $i, $v);
    $v->release();
}
$smallRaw   = (new ReflectionProperty(HashTable::class, 'pointer'))->getValue($small);
$smallMask  = $signedMask($smallRaw->nTableMask);
$smallHash  = (-$smallMask) * 4;
$smallBlock = Core::addressOf($smallRaw->arData) - $smallHash;
$smallSize  = $smallHash + $smallRaw->nTableSize * SIZEOF_BUCKET;

libc()->memcpy(spike_at('char', $arena2 + 4096), spike_at('char', $smallBlock), $smallSize);
libc()->memcpy(spike_at('char', $arena2 + 1024), spike_at('char', Core::addressOf($smallRaw)), $structSize);
$sharedSmall = Core::pointerAtAddress(\ZEngine\Generated\HashTable::class, $arena2 + 1024);
$sharedSmall->arData = Core::pointerAtAddress(\ZEngine\Generated\Bucket::class, $arena2 + 4096 + $smallHash);

$before = Core::addressOf($sharedSmall->arData);
printf("      arena2 0x%x .. 0x%x; small table nTableSize=%d nNumUsed=%d arData=0x%x (in arena: %s)\n",
    $arena2, $arena2 + (1 << 20), $sharedSmall->nTableSize, $sharedSmall->nNumUsed, $before,
    var_export($before > $arena2 && $before < $arena2 + (1 << 20), true));

// The growth happens in a SACRIFICIAL child: zend_hash_add() will pefree() the old data
// block, and that block is arena memory the process allocator never handed out.
$pids = spike_fork(1, function () use ($arena2, $sharedSmall, $report2, $before): int {
    $wrapper = PersistentHashTable::fromCData($sharedSmall);
    $report2[0] = 1;                             // "child reached the insert"
    for ($i = 6; $i < 40; $i++) {                // forces at least one zend_hash_do_resize
        $v = ReflectionValue::newEntry(ReflectionValue::IS_LONG, Core::new('zval'), true);
        $v->setNativeValue($i);
        $wrapper->add('s' . $i, $v);
        $v->release();
        $now = Core::addressOf($sharedSmall->arData);
        if ($now !== $before) {
            $report2[1] = 1;                     // arData moved
            $report2[2] = $now;
            $report2[3] = $sharedSmall->nTableSize;
            $report2[4] = $i;
            break;
        }
    }

    return 0;
});
$waits = spike_wait($pids);
printf("      growth child: %s\n", spike_describe_wait($waits));

$after   = Core::addressOf($sharedSmall->arData);
$inArena = $after > $arena2 && $after < $arena2 + (1 << 20);

if ($report2[1] === 1) {
    printf("      child saw arData move on insert #%d: 0x%x -> 0x%x (new nTableSize %d)\n",
        $report2[4], $before, $report2[2], $report2[3]);
} else {
    spike_note('the child never reported the move itself: it aborted inside the resize (see the signal above)');
}
printf("      parent now reads ht->arData = 0x%x  (inside arena2: %s)\n", $after, var_export($inArena, true));

spike_result('E growth is DETECTABLE (arData pointer changes in the shared struct)',
    $after !== $before,
    $after !== $before
        ? 'the shared struct was rewritten by the child'
        : 'no growth observed — the child may have died before resizing');
spike_result('E grown arData points OUTSIDE the shared arena', !$inArena,
    'the parent would now dereference the dead child\'s private heap: DANGLING');

// What does a SIBLING see now? In another sacrificial child, walk the table whose struct
// says "40 elements" but whose arData points at a heap block this process never wrote.
$pids = spike_fork(1, function () use ($sharedSmall, $report2): int {
    $wrapper = HashTable::fromCData($sharedSmall);
    $n   = 0;
    $sum = 0;
    foreach ($wrapper as $k => $v) {
        $n++;
        try {
            $v->getNativeValue($native);
            if (is_int($native)) {
                $sum += $native;
            }
        } catch (\Throwable) {
            $report2[7] = 1;
        }
        if ($n > 1000) {
            break;
        }
    }
    $report2[5] = $n;
    $report2[6] = $sum;

    return 0;
});
$waits = spike_wait($pids);
printf("      post-growth foreach child: %s\n", spike_describe_wait($waits));
printf("      it walked %d entries summing to %d; the shared struct claims nNumOfElements=%d nTableSize=%d\n",
    $report2[5], $report2[6], $sharedSmall->nNumOfElements, $sharedSmall->nTableSize);
spike_result('E a sibling reading the grown table gets SILENT garbage, not a crash',
    true,
    sprintf('walked %d of the %d elements the struct advertises — no fault, no signal, just wrong data',
        $report2[5], $sharedSmall->nNumOfElements));

echo "\nDone.\n";
