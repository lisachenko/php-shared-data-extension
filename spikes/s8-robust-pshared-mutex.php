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
 * S8 - are the arena's process-shared ROBUST mutexes actually correct?
 *
 * Three questions, in order of how badly a wrong answer would hurt:
 *
 *  1. Does a mutex placed in MAP_SHARED memory and initialized with
 *     PTHREAD_PROCESS_SHARED exclude a DIFFERENT PROCESS at all? (If pshared were
 *     ignored, every arena critical section would be decorative.)
 *  2. Does PTHREAD_MUTEX_ROBUST really hand the lock to the next taker with EOWNERDEAD
 *     when the owner is killed mid-section, instead of wedging the whole worker pool
 *     forever? (This is the entire reason a worker may be SIGKILLed by a supervisor.)
 *  3. Is the recovered lock usable afterwards - can the survivor lock/unlock it normally
 *     once it has been declared consistent?
 *
 * Run: php -d ffi.enable=1 spikes/s8-robust-pshared-mutex.php
 */

use Lisachenko\SharedData\Shm\Arena;

require __DIR__ . '/../vendor/autoload.php';

$arena = Arena::create(1 << 20);
$flag  = $arena->allocate(8);
$arena->writeWord($flag, 0);

$verdicts = [];

// --- 1. mutual exclusion across processes -----------------------------------------------
// The child takes stripe 2 and holds it for 300 ms while the parent tries to take it.
$arena->lockStripe(2);

$child = pcntl_fork();
if ($child === 0) {
    // Announce that the child is alive, then block until the parent releases the stripe
    $arena->writeWord($flag, 1);
    $arena->lockStripe(2);
    $arena->writeWord($flag, 2);
    usleep(200_000);
    $arena->unlockStripe(2);

    exit(0);
}

while ($arena->readWord($flag) === 0) {
    usleep(1_000);
}
usleep(50_000);
// The child is past its "I am alive" write and inside lockStripe(): still 1, not 2
$verdicts['child blocked while the parent held the stripe'] = $arena->readWord($flag) === 1;

$arena->unlockStripe(2);
pcntl_waitpid($child, $status);
$verdicts['child took the stripe once it was released'] = $arena->readWord($flag) === 2;

// --- 2. owner-died recovery -------------------------------------------------------------
// The child takes stripe 4, tells the parent, and is SIGKILLed while still holding it.
$arena->writeWord($flag, 0);

$victim = pcntl_fork();
if ($victim === 0) {
    $arena->lockStripe(4);
    $arena->writeWord($flag, 1);
    sleep(30); // killed long before this returns

    exit(0);
}

while ($arena->readWord($flag) !== 1) {
    usleep(1_000);
}
posix_kill($victim, SIGKILL);
pcntl_waitpid($victim, $status);

$recovered = $arena->lockStripe(4);
$verdicts['killed owner is reported as EOWNERDEAD to the next locker'] = $recovered;

// --- 3. the recovered lock still works --------------------------------------------------
$arena->unlockStripe(4);
$again = $arena->lockStripe(4);
$arena->unlockStripe(4);
$verdicts['recovered mutex behaves normally afterwards'] = $again === false;

$failed = false;
foreach ($verdicts as $question => $answer) {
    $failed = $failed || !$answer;
    printf("%-58s %s\n", $question, $answer ? 'YES' : 'NO');
}

printf("\nS8 verdict: %s\n", $failed ? 'FAILED' : 'robust pshared mutexes behave as the arena assumes');

exit($failed ? 1 : 0);
