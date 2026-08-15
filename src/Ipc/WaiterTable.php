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
 * Who to poke: a fixed table of wake-registry slots parked on one structure
 *
 * One word per entry, holding `wake slot + 1` so that a zero word means "free" without
 * costing a separate occupancy flag. The table is not a queue and has no ordering: waking
 * is level-triggered, so notifying everybody parked is always correct and notifying one
 * more than necessary costs a socket write and a re-poll.
 *
 * ## Locking
 *
 * register() and release() MUTATE the table and must be called with the owning structure's
 * lock held - two processes scanning for a free word without it could pick the same entry,
 * and the loser would park with nobody knowing about it. occupants() only READS single
 * aligned words, which never tear (EPIC #15, correction #2), and is deliberately used
 * without the lock: a notifier reads the table AFTER publishing its record and releasing the
 * mutex, so it never holds a lock while writing to a socket.
 */
final class WaiterTable
{
    public function __construct(
        private readonly Arena $arena,
        private readonly int $address,
        private readonly int $capacity,
    ) {
    }

    /**
     * Arena bytes a table of $capacity entries occupies
     */
    public static function bytesFor(int $capacity): int
    {
        return $capacity * 8;
    }

    /**
     * Parks a wake slot; the caller holds the structure's lock
     *
     * @return int|null Entry index to hand to release(), or null when the table is full
     *                  (the caller then falls back to polling with a bounded timeout)
     */
    public function register(int $wakeSlot): ?int
    {
        for ($entry = 0; $entry < $this->capacity; $entry++) {
            if ($this->arena->readWord($this->address + $entry * 8) === 0) {
                $this->arena->writeWord($this->address + $entry * 8, $wakeSlot + 1);

                return $entry;
            }
        }

        return null;
    }

    /**
     * Frees an entry taken by register(); the caller holds the structure's lock
     */
    public function release(?int $entry): void
    {
        if ($entry !== null) {
            $this->arena->writeWord($this->address + $entry * 8, 0);
        }
    }

    /**
     * Wake slots currently parked here, read without the lock (single-word loads)
     *
     * @return list<int>
     */
    public function occupants(): array
    {
        $slots = [];
        for ($entry = 0; $entry < $this->capacity; $entry++) {
            $parked = $this->arena->readWord($this->address + $entry * 8);
            if ($parked !== 0) {
                $slots[] = $parked - 1;
            }
        }

        return $slots;
    }
}
