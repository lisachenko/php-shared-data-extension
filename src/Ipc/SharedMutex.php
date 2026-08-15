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

namespace Lisachenko\SharedData\Ipc;

use Lisachenko\SharedData\Shm\Arena;

/**
 * A robust process-shared mutex userland can hold, without ever wedging the pool
 *
 * The lock itself is a `pthread_mutex_t` in the arena with PTHREAD_PROCESS_SHARED and
 * PTHREAD_MUTEX_ROBUST set - the only cross-process synchronization primitive reachable from
 * PHP, since FFI offers no atomics, no CAS and no fences. What this class adds is the policy
 * around it that a userland caller needs:
 *
 *  - **acquisition is bounded.** `lock()` is a trylock loop with backoff rather than a
 *    blocking `pthread_mutex_lock`, because a PHP process blocked in libc is a process that
 *    cannot run its scheduler, service its notification socket or answer a supervisor. A
 *    caller that genuinely wants to wait forever passes no timeout and still gets a loop.
 *  - **a died owner is recovered, never discarded.** EOWNERDEAD means the previous holder
 *    exited inside the critical section: the lock is granted, `pthread_mutex_consistent()`
 *    is called immediately (skipping it poisons the mutex arena-wide with ENOTRECOVERABLE,
 *    permanently - EPIC #15, correction #7), and the fact is reported through wasRecovered()
 *    so the caller can check whatever it was guarding. The result of a lock call is never
 *    thrown away.
 *
 * The same rules apply to the locks inside this package's own primitives; there they guard
 * single word stores, so recovery is trivially safe. Here the guarded state is the CALLER'S,
 * which is why recovery is surfaced rather than swallowed.
 */
final class SharedMutex
{
    /**
     * Backoff bounds of the acquisition loop, in microseconds
     */
    private const int MIN_BACKOFF = 20;
    private const int MAX_BACKOFF = 2_000;

    private bool $recovered = false;

    private bool $held = false;

    private function __construct(
        private readonly Arena $arena,
        private readonly int $address,
    ) {
    }

    /**
     * Creates a mutex in the arena, optionally publishing it under a name
     */
    public static function create(Arena $arena, ?string $name = null): self
    {
        $address = $arena->allocateMutex();
        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($arena, $address);
    }

    /**
     * Binds a mutex another process created, by address
     */
    public static function attach(Arena $arena, int $address): self
    {
        if (!$arena->contains($address, Arena::MUTEX_SLOT_SIZE)) {
            throw IpcException::notShared('mutex', $address);
        }

        return new self($arena, $address);
    }

    /**
     * Binds a mutex published in the arena roots directory
     */
    public static function open(Arena $arena, string $name): self
    {
        return self::attach($arena, $arena->requireRoot($name));
    }

    public function address(): int
    {
        return $this->address;
    }

    /**
     * Takes the lock if it is free right now
     */
    public function tryLock(): bool
    {
        $recovered = false;
        $taken     = $this->arena->tryLockMutexAt($this->address, $recovered);
        if ($taken) {
            // A recovered lock IS acquired: the EOWNERDEAD answer travels separately so
            // that neither half of the result can be dropped by accident
            $this->held      = true;
            $this->recovered = $this->recovered || $recovered;
        }

        return $taken;
    }

    /**
     * Takes the lock, retrying with backoff until it is free or the deadline passes
     *
     * @param float|null $timeout Seconds to keep trying; null retries forever
     *
     * @return bool Whether the lock is now held by this process
     */
    public function lock(?float $timeout = null): bool
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $backoff  = self::MIN_BACKOFF;

        while (true) {
            if ($this->tryLock()) {
                return true;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
            usleep($backoff);
            $backoff = min($backoff * 2, self::MAX_BACKOFF);
        }
    }

    public function unlock(): void
    {
        $this->held = false;
        $this->arena->unlockMutexAt($this->address);
    }

    /**
     * Whether this process currently believes it holds the lock
     */
    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * Whether any acquisition through this handle inherited the lock from a died owner
     *
     * True means some worker exited inside the critical section: the mutex was made
     * consistent again, and whatever it guards has to be checked by the code that knows
     * what "consistent" means for that structure.
     */
    public function wasRecovered(): bool
    {
        return $this->recovered;
    }
}
