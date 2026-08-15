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
 * S17 - can a closure compiled before the fork be invoked by address in several workers?
 *
 * The question behind Phase A of closure exchange (#20). A `zend_closure` is an object with a
 * `zend_function` embedded in it, pointing at opcodes, literals, a run-time cache and a table
 * of static variables. None of that is in the arena and none of it can be: what makes it work
 * is the fork itself - every child inherits the parent's address space, so a closure that
 * already existed maps at the same address, with the same op_array behind it, in every worker.
 *
 * The spike therefore checks the property that this package builds on, and the boundary that
 * it refuses to cross:
 *
 *  - three closures registered before the fork (pure, capturing a scalar, capturing a shared
 *    object) are invoked ROUNDS times in each of CHILDREN workers, and every result is
 *    verified against the value the parent computed for itself;
 *  - the closures' addresses are outside the arena, and the RECORDS naming them are inside it:
 *    nothing was copied, the arena carries the provenance and not the code;
 *  - the two ways of getting it wrong are refused rather than resolved: registering after the
 *    fork barrier, and registering from a worker (the case that produced the wrong-function
 *    execution S17 originally found).
 *
 * Nothing here is expected to crash - unlike the sweep this spike is named after, which held
 * post-fork addresses on purpose. That experiment is recorded on the gate for #15; this file
 * is the mechanism that came out of it, runnable from a checkout.
 *
 * Run: php -d ffi.enable=1 -d opcache.jit=off spikes/s17-prefork-closures.php
 */

use Lisachenko\SharedData\Ipc\ClosureProvenance;
use Lisachenko\SharedData\Ipc\ClosureProvenanceException;
use Lisachenko\SharedData\Ipc\NotShareableValueException;
use Lisachenko\SharedData\Ipc\ValueCodec;
use Lisachenko\SharedData\Ipc\ValueTag;
use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use ZEngine\Core;

require __DIR__ . '/../vendor/autoload.php';

const CHILDREN = 4;
const ROUNDS   = 50_000;
const FACTOR   = 7;

/**
 * A class whose instances travel through the arena must exist before the fork, like any other
 */
final class SpikeConfig
{
    public string $release = 'unset';

    public int $workers = 0;
}

$failures = 0;
$checks   = 0;

function check(string $claim, bool $holds): void
{
    global $failures, $checks;

    $checks++;
    if (!$holds) {
        $failures++;
    }
    printf("[%s] %s\n", $holds ? ' OK ' : 'FAIL', $claim);
}

Core::init();

$arena     = Arena::create(32 << 20);
$store     = PersistentStore::bootShared($arena, null, 's17_closures');
$allocator = new ArenaAllocator($arena);
$register  = ClosureProvenance::create($allocator, $store, 8);
$codec     = new ValueCodec($allocator, $store, $register);

$config          = new SpikeConfig();
$config->release = '2026.8';
$config->workers = CHILDREN;
$shared          = $store->persist(SpikeConfig::class, $config);

// ---------------------------------------------------------------------------------------
// Registration: the whole of Phase A's acceptance test, and it happens before the fork
// ---------------------------------------------------------------------------------------

$factor = FACTOR;

$pure     = $register->registerSharedClosure('pure', static fn (int $value): int => $value * $value);
$captures = $register->registerSharedClosure('captures', static fn (int $value): int => $value * $factor);
$reads    = $register->registerSharedClosure('reads', static fn (): string => $shared->release);

check('three pre-fork closures were registered', $register->count() === 3);
check(
    'the records live in the arena',
    $arena->contains($pure, 8) && $arena->contains($captures, 8) && $arena->contains($reads, 8),
);
check(
    'the closures themselves were NOT copied into the arena',
    !$arena->contains($register->closureAddressOf('pure'), 8)
    && !$arena->contains($register->closureAddressOf('captures'), 8),
);
check(
    'a closure that CAPTURES a shared object stays unbound, so its record carries no $this',
    $register->boundThisAddressOf('reads') === 0,
);

[$tag, $payload] = $codec->encode($register->closure('pure'));
check('a registered closure encodes as an address-shaped record', $tag === ValueTag::Closure && $tag->isAddress());
check('the payload is the record address, not the closure address', $payload === $pure);

$unregistered = static fn (): int => 1;
try {
    $codec->encode($unregistered);
    check('an unregistered closure is refused by the codec', false);
} catch (NotShareableValueException $exception) {
    check(
        'an unregistered closure is refused by the codec, naming registerSharedClosure()',
        str_contains($exception->getMessage(), 'registerSharedClosure'),
    );
}

$register->markForkBarrier();

try {
    $register->registerSharedClosure('too-late', static fn (): int => 2);
    check('registering after the fork barrier is refused', false);
} catch (ClosureProvenanceException) {
    check('registering after the fork barrier is refused', true);
}

// ---------------------------------------------------------------------------------------
// Invocation: every worker runs the same code, against its own copy-on-write engine state
// ---------------------------------------------------------------------------------------

$expectedSquares = 0;
$expectedScaled  = 0;
for ($round = 0; $round < ROUNDS; $round++) {
    $expectedSquares += $round * $round;
    $expectedScaled  += $round * FACTOR;
}

$results  = $arena->allocate(CHILDREN * 16);
$children = [];

for ($worker = 0; $worker < CHILDREN; $worker++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "cannot fork\n");

        exit(1);
    }
    if ($pid === 0) {
        $squares = $register->resolve($pure);
        $scaled  = $register->closure('captures');
        $reader  = $register->closure('reads');

        $sumSquares = 0;
        $sumScaled  = 0;
        for ($round = 0; $round < ROUNDS; $round++) {
            $sumSquares += $squares($round);
            $sumScaled  += $scaled($round);
        }

        $arena->writeWord($results + $worker * 16, $sumSquares);
        $arena->writeWord($results + $worker * 16 + 8, $sumScaled);

        // A worker may never register: its own closures are private to it, whatever it calls them
        $ownVerdict = 0;
        try {
            $register->registerSharedClosure('from-worker', static fn (): int => 3);
        } catch (ClosureProvenanceException) {
            $ownVerdict = 1;
        }

        exit($reader() === '2026.8' && $ownVerdict === 1 ? 0 : 9);
    }
    $children[] = $pid;
}

$exited = 0;
foreach ($children as $pid) {
    $status = 0;
    pcntl_waitpid($pid, $status);
    $exited += pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 ? 1 : 0;
    if (pcntl_wifsignaled($status)) {
        printf("[FAIL] worker %d died from signal %d\n", $pid, pcntl_wtermsig($status));
        $failures++;
    }
}

check(
    sprintf('%d workers read the captured shared object and were refused registration', CHILDREN),
    $exited === CHILDREN,
);

$allSquares = true;
$allScaled  = true;
for ($worker = 0; $worker < CHILDREN; $worker++) {
    $allSquares = $allSquares && $arena->readWord($results + $worker * 16) === $expectedSquares;
    $allScaled  = $allScaled && $arena->readWord($results + $worker * 16 + 8) === $expectedScaled;
}

check(sprintf('every worker computed the same %d squares', ROUNDS), $allSquares);
check(sprintf('every worker computed the same %d captured-scalar products', ROUNDS), $allScaled);
check('no worker left a record behind', $register->count() === 3);
check('no stripe lock had to be recovered', !$register->wasLockRecovered());

printf(
    "\n%s - %d checks, %d failures (arena watermark %d bytes)\n",
    $failures === 0 ? 'S17 GREEN' : 'S17 RED',
    $checks,
    $failures,
    $arena->watermark(),
);

exit($failures === 0 ? 0 : 1);
