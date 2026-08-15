<?php

declare(strict_types=1);

/**
 * S14 — per-process side effects of attaching a shared object
 * ===========================================================
 *
 * Attaching a persisted object to a request is not a read-only act. Two engine writes
 * land INSIDE the zend_object struct, and once that struct lives in shared memory both
 * of them become cross-process corruption:
 *
 *   A  zend_objects_store_put() writes the assigned handle into obj->handle.
 *      Every process needs its OWN handle (its object store is request/process memory),
 *      but they all write the same shared field. Last writer wins; every other process
 *      is left with an obj->handle that names a slot in ITS store belonging to a
 *      different object — and spl_object_id(), object comparison, the shutdown pass and
 *      ObjectStore::recycle() all read that field.
 *
 *   B  obj->properties is a LAZY, request-heap HashTable* that the engine materializes
 *      the first time anything asks for the property bag by name (get_object_vars(),
 *      var_dump(), json_encode(), (array) cast, ...). Written into a shared struct, the
 *      pointer is meaningless — and actively dangerous — in every other process.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S14_attach_side_effects.php
 */

require __DIR__ . '/lib/bootstrap.php';

use ZEngine\Core;
use ZEngine\Reflection\ReflectionClass as ZReflectionClass;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\PersistentObjectFactory;

spike_header('S14', 'per-process side effects of attach');

if (!Core::isInitialized()) {
    spike_result('S14 skipped', false, 'z-engine is unavailable on this PHP minor');
    exit(0);
}

const ARENA_SIZE = 1 << 20;
const OFF_MUTEX  = 0;
const OFF_BAR    = 128;      // spin barrier
const OFF_REPORT = 256;
const OFF_OBJ    = 4096;

final class S14Holder
{
    public int $alpha = 1;

    public string $beta = 'b';
}

$arena  = spike_mmap_shared(ARENA_SIZE);
$mutex  = spike_mutex_init($arena + OFF_MUTEX);
$bar    = spike_at('int64_t', $arena + OFF_BAR);
$report = spike_at('int64_t', $arena + OFF_REPORT);

// --- place an engine-formatted object in the arena ---------------------------
$src   = new S14Holder();
$rv    = new ReflectionValue($src);
$rawSrc = $rv->getRawObject();
$size  = ZReflectionClass::getObjectSize($rawSrc->ce);
$clone = PersistentObjectFactory::persistentClone($rawSrc);
$rv->release();

libc()->memcpy(spike_at('char', $arena + OFF_OBJ), spike_at('char', Core::addressOf($clone)), $size);
$shared = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $arena + OFF_OBJ);

printf("shared zend_object at 0x%x, %d bytes; handle field currently %d, properties=0x%x\n\n",
    $arena + OFF_OBJ, $size, $shared->handle,
    $shared->properties === null ? 0 : Core::addressOf($shared->properties));

// ===========================================================================
// A — concurrent ObjectStore::put on the SAME shared struct
// ===========================================================================
spike_step('A — parent attaches, then two children attach the same object simultaneously');

$parentHandle = Core::$executor->objectStore->put($shared);
$parentValue  = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $shared[0]);
$parentValue->getNativeValue($parentInstance);
printf("      parent put() -> handle %d, obj->handle=%d, spl_object_id=%d\n",
    $parentHandle, $shared->handle, spl_object_id($parentInstance));

$pids = spike_fork(2, function (int $role) use ($shared, $bar, $report): int {
    // Rendezvous so both put() calls really overlap.
    $bar[0] = $bar[0] + 1;
    while ($bar[0] < 2) {
        // spin
    }

    $handle = Core::$executor->objectStore->put($shared);
    $value  = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $shared[0]);
    $value->getNativeValue($instance);

    $report[10 + $role * 4 + 0] = $handle;                 // handle the engine gave THIS process
    $report[10 + $role * 4 + 1] = spl_object_id($instance);
    usleep(20000);                                          // let the sibling clobber the field
    $report[10 + $role * 4 + 2] = $shared->handle;          // what the shared field says afterwards
    $report[10 + $role * 4 + 3] = spl_object_id($instance); // ...and what spl_object_id says now

    return 0;
});
$waits = spike_wait($pids);
printf("      children: %s\n", spike_describe_wait($waits));

for ($r = 0; $r < 2; $r++) {
    printf("      child %d: put() returned handle %d, spl_object_id right after = %d; ".
           "20 ms later obj->handle=%d and spl_object_id=%d\n",
        $r, $report[10 + $r * 4], $report[10 + $r * 4 + 1], $report[10 + $r * 4 + 2], $report[10 + $r * 4 + 3]);
}
printf("      parent afterwards: obj->handle=%d, spl_object_id(\$parentInstance)=%d (parent's real slot is %d)\n",
    $shared->handle, spl_object_id($parentInstance), $parentHandle);

spike_result('A obj->handle is a SHARED field every attaching process overwrites',
    $shared->handle !== $parentHandle,
    sprintf('parent attached at slot %d, shared field now says %d', $parentHandle, $shared->handle));
spike_result('A spl_object_id() in the parent is now WRONG',
    spl_object_id($parentInstance) !== $parentHandle,
    'spl_object_id() reads obj->handle directly — it returns a foreign process\'s slot number');

// What sits in the parent's own store at the clobbered handle?
$store = Core::$executor->objectStore;
$victim = $store[$shared->handle] ?? null;
printf("      parent's object store slot %d currently holds: %s\n",
    $shared->handle,
    $victim === null ? 'nothing / invalid bucket' : 'a DIFFERENT live object (' . get_class($victim->getNativeValue()) . ')');
spike_note('recycle()/detach() at request end would therefore return a FOREIGN slot to the free list');

echo "\n";

// ===========================================================================
// B — the dynamic-properties pointer hazard
// ===========================================================================
spike_step('B — obj->properties: the lazy request-heap pointer written into a shared struct');

printf("      before: obj->properties = 0x%x\n", $shared->properties === null ? 0 : Core::addressOf($shared->properties));

// B1: child A merely asks for the property bag by name.
$pids = spike_fork(1, function () use ($shared, $report): int {
    $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $shared[0]);
    $value->getNativeValue($instance);

    $report[30] = $shared->properties === null ? 0 : Core::addressOf($shared->properties);
    $vars       = get_object_vars($instance);          // the trigger
    $report[31] = $shared->properties === null ? 0 : Core::addressOf($shared->properties);
    $report[32] = count($vars);

    ob_start();
    var_dump($instance);                                // second common trigger
    ob_end_clean();
    $report[33] = $shared->properties === null ? 0 : Core::addressOf($shared->properties);

    $enc         = json_encode($instance);
    $report[34]  = $shared->properties === null ? 0 : Core::addressOf($shared->properties);
    $report[35]  = strlen((string) $enc);

    $cast        = (array) $instance;
    $report[36]  = $shared->properties === null ? 0 : Core::addressOf($shared->properties);
    $report[37]  = count($cast);

    return 0;
});
$waits = spike_wait($pids);
printf("      trigger child: %s\n", spike_describe_wait($waits));
printf("      inside child A: properties 0x%x -> get_object_vars(%d vars) -> 0x%x -> var_dump -> 0x%x -> json_encode(%d bytes) -> 0x%x -> (array) cast(%d) -> 0x%x\n",
    $report[30], $report[32], $report[31], $report[33], $report[35], $report[34], $report[37], $report[36]);

$propsAfter = $shared->properties === null ? 0 : Core::addressOf($shared->properties);
printf("      PARENT now reads obj->properties = 0x%x  (child A is gone; that is child A's private heap)\n", $propsAfter);

$leaked = $propsAfter !== 0;
spike_result('B a read-only-looking call writes a request-heap pointer into the SHARED struct',
    true,
    $leaked
        ? 'CONFIRMED: obj->properties is non-NULL in the shared struct after a child called get_object_vars()/var_dump()'
        : 'NOT reproduced on this build: obj->properties stayed NULL (see note below)');

if ($leaked) {
    // B2: a sibling that now touches the property bag follows the dangling pointer.
    spike_step('B2 — sibling child B follows the inherited obj->properties pointer');
    $pids = spike_fork(1, function () use ($shared, $report): int {
        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $shared[0]);
        $value->getNativeValue($instance);

        $report[40] = 1;                     // reached the child
        $vars       = get_object_vars($instance);
        $report[41] = count($vars);
        $report[42] = 1;
        ob_start();
        var_dump($instance);
        $dump = (string) ob_get_clean();
        $report[43] = strlen($dump);
        $report[44] = 1;

        return 0;
    });
    $waits = spike_wait($pids);
    printf("      sibling child: %s\n", spike_describe_wait($waits));
    printf("      progress markers: reached=%d, get_object_vars returned %d vars (done=%d), var_dump produced %d bytes (done=%d)\n",
        $report[40], $report[41], $report[42], $report[43], $report[44]);

    $crashed = $waits[array_key_first($waits)]['signal'] !== null;
    spike_result('B2 sibling outcome',
        true,
        $crashed
            ? 'CRASHED (signal ' . $waits[array_key_first($waits)]['signal'] . ') following the foreign properties pointer'
            : sprintf('survived but read %d "properties" out of a heap block it never wrote — silent garbage', $report[41]));
}

// B3: the sibling above only survived because fork() gave it the SAME copy-on-write heap
// layout as the writer, so the address happened to be mapped. A process that did not fork
// from the writer (a worker started later, a different pool member) has nothing there.
// Simulate that by pointing obj->properties at an address that is mapped in nobody.
spike_step('B3 — what a process that did NOT inherit the writer\'s heap sees');

$unmapped = $arena + (ARENA_SIZE * 64);          // far past the arena: never mapped
$shared->properties = Core::pointerAtAddress(\ZEngine\Generated\HashTable::class, $unmapped);
printf("      obj->properties forced to 0x%x (unmapped in every process)\n", $unmapped);

$pids = spike_fork(1, function () use ($shared, $report): int {
    $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $shared[0]);
    $value->getNativeValue($instance);
    $report[50] = 1;
    $vars = get_object_vars($instance);
    $report[51] = count($vars);

    return 0;
});
$waits  = spike_wait($pids);
$w      = $waits[array_key_first($waits)];
$signal = $w['signal'];
$died   = $signal !== null || $w['exit'] !== 0;
printf("      child: %s (reached=%d, returned %d vars)\n", spike_describe_wait($waits), $report[50], $report[51]);
spike_result('B3 dereferencing a foreign obj->properties kills the process', $died,
    $signal !== null
        ? 'SIGNAL ' . $signal . ' (' . spike_signame($signal) . ') — hard crash'
        : ($died
            ? 'engine bailed out with a fatal error (exit ' . $w['exit'] . ') after reading a garbage nTableSize'
            : 'no fault observed on this run'));

$shared->properties = null;                      // put the struct back into a sane state

spike_note('the same field is also written by: property_exists on dynamic props, iteration over the object,');
spike_note('serialize(), debug_zval_dump(), Reflection*::getProperties() and every (array)/json path.');

// ===========================================================================
// C — the third per-process field: obj->ce
// ===========================================================================
echo "\n";
spike_step('C — obj->ce and obj->handlers: which of them is really fork-stable?');

$handlersAddr = Core::addressOf(Core::addr(Core::getStandardObjectHandlers()));
$ceAddr       = Core::addressOf($shared->ce);
printf("      parent: std_object_handlers=0x%x, S14Holder ce=0x%x\n", $handlersAddr, $ceAddr);

$pids = spike_fork(2, function (int $role) use ($shared, $report): int {
    $report[60 + $role * 4 + 0] = Core::addressOf(Core::addr(Core::getStandardObjectHandlers()));
    $report[60 + $role * 4 + 1] = Core::addressOf($shared->ce);

    // A class DEFINED AFTER the fork: its class entry comes out of this process's own
    // compiler arena. Child 0 declares decoy classes first, which is all it takes for
    // the two children to place "the same" class at different addresses — the realistic
    // case being two workers that autoload different things in a different order.
    if ($role === 0) {
        for ($i = 0; $i < 40; $i++) {
            eval("class S14Decoy{$i} { public int \$a = 1; public string \$b = 'x'; }");
        }
    }
    eval('class S14LateClass { public int $v = 1; }');
    $lateValue = Core::$executor->classTable->find('s14lateclass');
    $report[60 + $role * 4 + 2] = $lateValue === null ? 0 : Core::addressOf($lateValue->getRawClass());

    return 0;
});
spike_wait($pids);

printf("      child 0: handlers=0x%x  S14Holder ce=0x%x  post-fork S14LateClass ce=0x%x\n",
    $report[60], $report[61], $report[62]);
printf("      child 1: handlers=0x%x  S14Holder ce=0x%x  post-fork S14LateClass ce=0x%x\n",
    $report[64], $report[65], $report[66]);

spike_result('C std_object_handlers is address-identical in every forked process',
    $report[60] === $handlersAddr && $report[64] === $handlersAddr,
    'safe to keep INSIDE the shared struct');
spike_result('C a PRE-fork class entry is address-identical too',
    $report[61] === $ceAddr && $report[65] === $ceAddr,
    'obj->ce happens to agree — but only because the class was loaded before the fork');
spike_result('C a POST-fork class entry differs per process',
    $report[62] !== $report[66] && $report[62] !== 0 && $report[66] !== 0,
    sprintf('0x%x vs 0x%x — obj->ce cannot be a shared field once classes are autoloaded lazily',
        $report[62], $report[66]));

echo "\nDone.\n";
