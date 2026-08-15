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
 * "Wait until the outstanding work reaches zero", across processes
 *
 * A counter in the arena plus a waiter table: add() before handing work out, done() when a
 * worker finishes a unit, wait() to park until the count is zero. The counter is shared, so
 * it does not matter which process increments and which decrements - a parent may add() for
 * four children and any of them may done() from wherever it runs.
 *
 * ```text
 *   header (4 words)   counter | mutex address | waiter capacity | waiters parked
 *   waiters            waiter capacity words - wake slots parked on zero
 * ```
 *
 * A negative counter is a hard error rather than a clamp: done() called more often than
 * add() means the family lost track of its own work, and continuing would let wait() return
 * while units are still running. The counter is left where it was so the miscount is visible.
 */
final class SharedWaitGroup
{
    public const int DEFAULT_WAITERS = 16;

    private const float WAIT_SLICE = 0.05;

    private const int WORD_COUNTER         = 0;
    private const int WORD_MUTEX           = 1;
    private const int WORD_WAITER_CAPACITY = 2;
    private const int WORD_PARKED          = 3;
    private const int HEADER_WORDS         = 4;

    private readonly int $mutex;

    private readonly WaiterTable $waiters;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly Arena $arena,
        private readonly WakeRegistry $wake,
        private readonly int $address,
    ) {
        if (!$arena->contains($address, self::HEADER_WORDS * 8)) {
            throw IpcException::notShared('wait group', $address);
        }
        $this->mutex   = $arena->readWord($address + self::WORD_MUTEX * 8);
        $this->waiters = new WaiterTable(
            $arena,
            $address + self::HEADER_WORDS * 8,
            $arena->readWord($address + self::WORD_WAITER_CAPACITY * 8),
        );
    }

    public static function create(
        Arena $arena,
        WakeRegistry $wake,
        int $waiterCapacity = self::DEFAULT_WAITERS,
        ?string $name = null,
    ): self {
        if ($waiterCapacity <= 0) {
            throw IpcException::invalidCapacity('Wait group waiter table', $waiterCapacity);
        }
        $address = $arena->allocate(self::HEADER_WORDS * 8 + WaiterTable::bytesFor($waiterCapacity), 64);
        $mutex   = $arena->allocateMutex();

        $arena->writeWord($address + self::WORD_MUTEX * 8, $mutex);
        $arena->writeWord($address + self::WORD_WAITER_CAPACITY * 8, $waiterCapacity);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($arena, $wake, $address);
    }

    public static function attach(Arena $arena, WakeRegistry $wake, int $address): self
    {
        return new self($arena, $wake, $address);
    }

    public static function open(Arena $arena, WakeRegistry $wake, string $name): self
    {
        return new self($arena, $wake, $arena->requireRoot($name));
    }

    public function address(): int
    {
        return $this->address;
    }

    /**
     * Outstanding units of work (single aligned word read, so no lock)
     */
    public function count(): int
    {
        return $this->arena->readWord($this->address + self::WORD_COUNTER * 8);
    }

    /**
     * Announces $delta more units of work
     */
    public function add(int $delta = 1): int
    {
        return $this->adjust($delta);
    }

    /**
     * Marks one unit finished, waking everybody parked once the count reaches zero
     */
    public function done(): int
    {
        $value = $this->adjust(-1);
        if ($value === 0) {
            $this->wake->notifyAll(
                $this->waiters->occupants(),
                new WakeEvent(WakeOpcode::Wake, ($this->address >> 4) & 0xFFFFFFFF),
            );
        }

        return $value;
    }

    /**
     * Parks until the counter reaches zero
     *
     * @param float|null $timeout Seconds to wait; null waits forever
     *
     * @return bool Whether the counter actually reached zero
     */
    public function wait(?float $timeout = null): bool
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $wakeSlot = $this->wake->slot();

        while (true) {
            $recovered = $this->arena->lockMutexAt($this->mutex);

            $reached = $this->arena->readWord($this->address + self::WORD_COUNTER * 8) <= 0;
            $entry   = null;
            if (!$reached) {
                // Registering and re-reading the counter in ONE critical section is what
                // makes a lost wakeup impossible: a done() that zeroes the counter after
                // this point necessarily sees this entry
                $entry = $this->waiters->register($wakeSlot);
                $this->bumpParked(1);
            }

            $this->arena->unlockMutexAt($this->mutex);

            $this->recoveredLock = $this->recoveredLock || $recovered;

            if ($reached) {
                return true;
            }
            if ($deadline === null) {
                $this->wake->wait(self::WAIT_SLICE);
            } else {
                $remaining = $deadline - microtime(true);
                if ($remaining > 0) {
                    $this->wake->wait(min($remaining, self::WAIT_SLICE));
                }
            }

            $this->unpark($entry);

            if ($deadline !== null && microtime(true) >= $deadline) {
                return $this->count() <= 0;
            }
        }
    }

    /**
     * Whether a lock of this group was ever recovered from a worker that died holding it
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    private function adjust(int $delta): int
    {
        $recovered = $this->arena->lockMutexAt($this->mutex);

        $value    = $this->arena->readWord($this->address + self::WORD_COUNTER * 8) + $delta;
        $negative = $value < 0;
        if (!$negative) {
            $this->arena->writeWord($this->address + self::WORD_COUNTER * 8, $value);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($negative) {
            // The counter keeps its old value on purpose: the miscount stays visible to
            // every process instead of being papered over with a clamp to zero
            throw IpcException::negativeCounter($value);
        }

        return $value;
    }

    private function unpark(?int $entry): void
    {
        if ($entry === null) {
            return;
        }

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $this->waiters->release($entry);
        $this->bumpParked(-1);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Moves the parked counter; the caller holds the group's lock
     */
    private function bumpParked(int $delta): void
    {
        $parked = $this->arena->readWord($this->address + self::WORD_PARKED * 8) + $delta;
        $this->arena->writeWord($this->address + self::WORD_PARKED * 8, max($parked, 0));
    }
}
