<?php

declare(strict_types=1);

/**
 * S17 — closures across fork
 * ==========================
 *
 * (a) A closure COMPILED BEFORE fork: is it safe to invoke concurrently in several
 *     children? (static closure with $this = null, and a closure with `use` scalars.)
 * (b) A closure CREATED AFTER fork inside child A, whose zend_closure address is handed
 *     to child B over a pipe: what happens when B invokes it?
 * (c) An inventory of every pointer a zend_closure carries, so the cost of arena-cloning
 *     one can be judged.
 *
 *   typedef struct _zend_closure {
 *       zend_object       std;                  // its own object header (handle!)
 *       zend_function     func;                 // an EMBEDDED zend_op_array, not a pointer
 *       zval              this_ptr;
 *       zend_class_entry *called_scope;
 *       zif_handler       orig_internal_handler;
 *   } zend_closure;
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off S17_closures_across_fork.php
 */

require __DIR__ . '/lib/bootstrap.php';

use ZEngine\Core;
use ZEngine\Generated\zend_closure;
use ZEngine\Reflection\ReflectionValue;

spike_header('S17', 'closures across fork');

if (!Core::isInitialized()) {
    spike_result('S17 skipped', false, 'z-engine is unavailable on this PHP minor');
    exit(0);
}

const ARENA_SIZE = 1 << 20;
const OFF_REPORT = 256;

$arena  = spike_mmap_shared(ARENA_SIZE);
$report = spike_at('int64_t', $arena + OFF_REPORT);

/** @return object zend_closure* */
function closurePointer(\Closure $closure): object
{
    $value = new ReflectionValue($closure);
    $raw   = $value->getRawObject();
    $ptr   = Core::cast(zend_closure::class, $raw);
    $value->release();

    return $ptr;
}

// ===========================================================================
// (a) closures compiled BEFORE fork
// ===========================================================================
spike_step('(a) closures compiled PRE-fork, invoked concurrently by two children');

$base   = 1000;
$factor = 7;

$staticClosure = static function (int $x): int {      // no $this at all
    return $x * 3 + 1;
};
$useClosure = function (int $x) use ($base, $factor): int {   // captured scalars
    return $base + $x * $factor;
};

final class S17Scope
{
    public int $offset = 5;

    public function make(): \Closure
    {
        return function (int $x): int {
            return $x + $this->offset;
        };
    }
}
$boundClosure = (new S17Scope())->make();             // bound $this

$INVOKES = 100000;

foreach (['static' => $staticClosure, 'use' => $useClosure, 'bound' => $boundClosure] as $label => $c) {
    $p = closurePointer($c);
    printf("      %-6s closure: zend_closure at 0x%x, handle %d, fn_flags=0x%x (HEAP_RT_CACHE=%s), op_array.opcodes=0x%x\n",
        $label,
        Core::addressOf($p),
        $p->std->handle,
        $p->func->common->fn_flags,
        var_export(($p->func->common->fn_flags & Core::ZEND_ACC_HEAP_RT_CACHE) !== 0, true),
        Core::addressOf($p->func->op_array->opcodes));
}

$t0   = microtime(true);
$pids = spike_fork(2, function (int $role) use ($staticClosure, $useClosure, $boundClosure, $report, $INVOKES): int {
    $bad = 0;
    $acc = 0;
    for ($i = 0; $i < $INVOKES; $i++) {
        $a = $staticClosure($i);
        $b = $useClosure($i);
        $c = $boundClosure($i);
        if ($a !== $i * 3 + 1 || $b !== 1000 + $i * 7 || $c !== $i + 5) {
            $bad++;
        }
        $acc += $a + $b + $c;
    }
    $report[$role * 4 + 0] = $bad;
    $report[$role * 4 + 1] = $acc;
    $report[$role * 4 + 2] = spl_object_id($staticClosure);

    return 0;
});
$waits = spike_wait($pids);
$dt    = microtime(true) - $t0;

printf("      children: %s (%.2f s, %d invocations each of 3 closures)\n",
    spike_describe_wait($waits), $dt, $INVOKES);
printf("      child 0: %d wrong results, checksum %d, closure spl_object_id %d\n", $report[0], $report[1], $report[2]);
printf("      child 1: %d wrong results, checksum %d, closure spl_object_id %d\n", $report[4], $report[5], $report[6]);

spike_result('(a) pre-fork closures invoke correctly and identically in both children',
    $report[0] === 0 && $report[4] === 0 && $report[1] === $report[5] && $report[1] > 0,
    'op_array, literals and the captured statics are all COW-shared read-only data');
spike_note('run_time_cache is per-closure heap memory (ZEND_ACC_HEAP_RT_CACHE): each child COW-copies its own');

// ===========================================================================
// (b) a closure created AFTER fork, invoked in a sibling
// ===========================================================================
echo "\n";
spike_step('(b) closure created POST-fork in child A, its address handed to child B over a pipe');

[$parentEnd, $childEnd] = spike_pipe();

$pidA = pcntl_fork();
if ($pidA === 0) {
    fclose($parentEnd);
    // Push the heap forward so the new closure does NOT land on a page the parent
    // already has: this is exactly the COW divergence a post-fork allocation causes.
    $ballast = [];
    for ($i = 0; $i < 20000; $i++) {
        $ballast[] = str_repeat('x', 64) . $i;
    }
    $magic = 987654321;
    $late  = static function (int $x) use ($magic): int {
        return $x + $magic;
    };
    $ptr = closurePointer($late);
    fwrite($childEnd, pack('J', Core::addressOf($ptr)));
    fwrite($childEnd, pack('J', $late(1)));
    fflush($childEnd);
    usleep(200000);                     // stay alive briefly, then die WITH its heap
    spike_hard_exit(0);
}
fclose($childEnd);
$payload = fread($parentEnd, 16);
$vals    = unpack('Jaddr/Jresult', (string) $payload);
$lateAddr = $vals['addr'];
printf("      child A built the closure at 0x%x; in child A it returns %d for input 1\n", $lateAddr, $vals['result']);
pcntl_waitpid($pidA, $st);

// Child B: a SIBLING of A that never saw A's allocation.
$pidB = pcntl_fork();
if ($pidB === 0) {
    $report[20] = 1;                    // reached
    $raw   = Core::pointerAtAddress(\ZEngine\Generated\zend_object::class, $lateAddr);
    $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $raw[0]);
    $value->getNativeValue($alien);
    $report[21] = 1;                    // materialized a PHP value at that address
    $report[22] = is_object($alien) ? 1 : 0;
    $report[23] = $alien instanceof \Closure ? 1 : 0;
    if ($alien instanceof \Closure) {
        $report[24] = 1;                // about to invoke
        $r = $alien(1);
        $report[25] = 1;                // survived the invoke
        $report[26] = is_int($r) ? $r : -1;
    }
    spike_hard_exit(0);
}
$waits = spike_wait([$pidB]);
$w     = $waits[$pidB];
printf("      child B: %s\n", spike_describe_wait($waits));
printf("      markers: reached=%d materialized=%d is_object=%d is_Closure=%d invoked=%d survived=%d result=%d\n",
    $report[20], $report[21], $report[22], $report[23], $report[24], $report[25], $report[26]);

$divergent = $w['signal'] !== null || $w['exit'] !== 0 || $report[23] !== 1 || $report[26] !== 987654322;
spike_result('(b) invoking a sibling-built closure by address is UNSAFE', $divergent,
    $w['signal'] !== null
        ? 'child B died with signal ' . $w['signal'] . ' (' . spike_signame($w['signal']) . ')'
        : ($report[23] !== 1
            ? 'the address did not even hold a Closure in child B — COW divergence'
            : ($report[26] !== 987654322
                ? 'child B invoked it and got ' . $report[26] . ' instead of 987654322'
                : 'child B happened to agree — the heap had not diverged at that address (rerun)')));
spike_note('every post-fork allocation lands on a private COW page; addresses are only meaningful');
spike_note('inside the process that allocated them. Closures therefore cannot be shared by address.');

// ===========================================================================
// (c) pointer inventory of a zend_closure
// ===========================================================================
echo "\n";
spike_step('(c) every pointer a zend_closure carries (feasibility of arena-cloning)');

$probe = function (int $x) use ($base): int {
    static $calls = 0;
    $calls++;

    return $x + $base + $calls;
};
$probe(1);                              // materialize static_variables_ptr

$p  = closurePointer($probe);
$oa = $p->func->op_array;

$fields = [
    'zend_closure (whole struct)'      => Core::addressOf($p),
    'std.ce (Closure class entry)'     => $p->std->ce === null ? 0 : Core::addressOf($p->std->ce),
    'std.handlers'                     => $p->std->handlers === null ? 0 : Core::addressOf($p->std->handlers),
    'func.op_array.function_name'      => $oa->function_name === null ? 0 : Core::addressOf($oa->function_name),
    'func.op_array.scope'              => $oa->scope === null ? 0 : Core::addressOf($oa->scope),
    'func.op_array.arg_info'           => $oa->arg_info === null ? 0 : Core::addressOf($oa->arg_info),
    'func.op_array.attributes'         => $oa->attributes === null ? 0 : Core::addressOf($oa->attributes),
    'func.op_array.run_time_cache__ptr' => $oa->run_time_cache__ptr === null ? 0 : Core::addressOf($oa->run_time_cache__ptr),
    'func.op_array.opcodes'            => $oa->opcodes === null ? 0 : Core::addressOf($oa->opcodes),
    'func.op_array.static_variables'   => $oa->static_variables === null ? 0 : Core::addressOf($oa->static_variables),
    'func.op_array.static_variables_ptr__ptr' => $oa->static_variables_ptr__ptr === null ? 0 : Core::addressOf($oa->static_variables_ptr__ptr),
    'func.op_array.vars'               => $oa->vars === null ? 0 : Core::addressOf($oa->vars),
    'func.op_array.refcount'           => $oa->refcount === null ? 0 : Core::addressOf($oa->refcount),
    'func.op_array.literals'           => $oa->literals === null ? 0 : Core::addressOf($oa->literals),
    'func.op_array.filename'           => $oa->filename === null ? 0 : Core::addressOf($oa->filename),
    'func.op_array.dynamic_func_defs'  => $oa->dynamic_func_defs === null ? 0 : Core::addressOf($oa->dynamic_func_defs),
    'func.op_array.live_range'         => $oa->live_range === null ? 0 : Core::addressOf($oa->live_range),
    'func.op_array.try_catch_array'    => $oa->try_catch_array === null ? 0 : Core::addressOf($oa->try_catch_array),
    'this_ptr.value'                   => $p->this_ptr->value->lval,
    'called_scope'                     => $p->called_scope === null ? 0 : Core::addressOf($p->called_scope),
];

printf("      %-42s %-18s %s\n", 'field', 'address', 'notes');
foreach ($fields as $name => $addr) {
    printf("      %-42s 0x%-16x %s\n", $name, $addr, $addr === 0 ? '(null)' : '');
}
printf("      counts: num_args=%d last_var=%d T=%d last(opcodes)=%d last_literal=%d cache_size=%d num_dynamic_func_defs=%d\n",
    $oa->num_args, $oa->last_var, $oa->T, $oa->last, $oa->last_literal, $oa->cache_size, $oa->num_dynamic_func_defs);
printf("      byte cost of a deep clone: opcodes %d*%d=%d, literals %d*%d=%d, vars %d*8=%d, arg_info %d*%d=%d\n",
    $oa->last, FFI::sizeof(Core::new('zend_op')), $oa->last * FFI::sizeof(Core::new('zend_op')),
    $oa->last_literal, FFI::sizeof(Core::new('zval')), $oa->last_literal * FFI::sizeof(Core::new('zval')),
    $oa->last_var, $oa->last_var * 8,
    $oa->num_args, FFI::sizeof(Core::new('zend_arg_info')), $oa->num_args * FFI::sizeof(Core::new('zend_arg_info')));

$nonNull = count(array_filter($fields, static fn (int $a): bool => $a !== 0));
spike_result('(c) pointer inventory taken', true,
    sprintf('%d of %d zend_closure/op_array pointer fields are non-NULL for a trivial closure', $nonNull, count($fields)));

// Are the op_array pointers stable across fork? (They must be, for (a) to work.)
$pids = spike_fork(1, function () use ($probe, $report): int {
    $q   = closurePointer($probe);
    $qoa = $q->func->op_array;
    $report[30] = Core::addressOf($q);
    $report[31] = $qoa->opcodes === null ? 0 : Core::addressOf($qoa->opcodes);
    $report[32] = $qoa->literals === null ? 0 : Core::addressOf($qoa->literals);
    $report[33] = $qoa->static_variables === null ? 0 : Core::addressOf($qoa->static_variables);
    $report[34] = $q->std->handle;

    return 0;
});
spike_wait($pids);
printf("      child sees the SAME closure at 0x%x (opcodes 0x%x, literals 0x%x, static_variables 0x%x, handle %d)\n",
    $report[30], $report[31], $report[32], $report[33], $report[34]);
spike_result('(c) a pre-fork closure keeps identical addresses in the child',
    $report[30] === Core::addressOf($p) && $report[31] === ($oa->opcodes === null ? 0 : Core::addressOf($oa->opcodes)));

spike_note('run_time_cache__ptr and static_variables_ptr__ptr point into the REQUEST arena, not into');
spike_note('the compiled op_array: an arena-resident closure would share those per-request slots');
spike_note('between processes. Any closure design must re-mint them per process.');

echo "\nDone.\n";
