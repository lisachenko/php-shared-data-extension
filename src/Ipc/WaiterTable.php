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
 * One word per entry, holding `owner pid << 32 | wake slot + 1` so that a zero word means
 * "free" without costing a separate occupancy flag. The table is not a queue and has no
 * ordering: waking is level-triggered, so notifying everybody parked is always correct and
 * notifying one more than necessary costs a socket write and a re-poll.
 *
 * ## Why the owner pid rides in the same word
 *
 * A waiter that parks inside a blocking call always takes its entry back on the way out, so
 * for those the entry can never outlive its owner. A waiter REGISTERED from a consumer's own
 * event loop ({@see SharedChannel::registerReceiver()}) is a different lifetime: if that
 * process dies while registered, the entry stays, and on a rendezvous channel a stale entry
 * is the difference between "a partner is present" and "nobody is there". Recording the pid
 * next to the slot is what lets {@see SharedChannel::reapDeadWaiters()} tell a live
 * registration from the remains of a dead worker - and the recycled-slot case with it, since
 * the wake registry hands a dead owner's slot to the next process that claims one.
 *
 * The pid is packed into the high half of the SAME aligned word rather than into a second
 * one, because two words are a 16-byte record and a 16-byte record tears (EPIC #15,
 * correction #1). Linux pids fit comfortably in 32 bits, so one word carries both and every
 * read of an entry stays a single non-tearing load.
 *
 * ## Locking
 *
 * register() and release() MUTATE the table and must be called with the owning structure's
 * lock held - two processes scanning for a free word without it could pick the same entry,
 * and the loser would park with nobody knowing about it. occupants() and entries() only READ
 * single aligned words, which never tear (EPIC #15, correction #2), and are deliberately used
 * without the lock: a notifier reads the table AFTER publishing its record and releasing the
 * mutex, so it never holds a lock while writing to a socket.
 */
final class WaiterTable
{
    /**
     * Low half of an entry word: the wake slot, biased by one so that zero means "free"
     */
    private const int SLOT_MASK = 0xFFFFFFFF;

    private const int PID_SHIFT = 32;

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
     * @param int $ownerPid Process the entry belongs to, so a registration that outlives its
     *                      owner can be recognized; 0 records no owner, which is what a
     *                      waiter parked inside a blocking call wants - it always releases
     *                      its own entry, so there is nothing to reap
     *
     * @return int|null Entry index to hand to release(), or null when the table is full
     *                  (the caller then falls back to polling with a bounded timeout)
     */
    public function register(int $wakeSlot, int $ownerPid = 0): ?int
    {
        for ($entry = 0; $entry < $this->capacity; $entry++) {
            if ($this->arena->readWord($this->address + $entry * 8) === 0) {
                $this->arena->writeWord(
                    $this->address + $entry * 8,
                    ($ownerPid << self::PID_SHIFT) | ($wakeSlot + 1),
                );

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
                $slots[] = ($parked & self::SLOT_MASK) - 1;
            }
        }

        return $slots;
    }

    /**
     * Every occupied entry with the word that occupies it, read without the lock
     *
     * The raw word comes back with the decoded parts because a reaper has to re-verify it
     * under the lock before releasing anything: between the scan and the critical section the
     * owner may have released the entry and a third process may have taken it for itself.
     *
     * @return list<array{entry: int, slot: int, pid: int, word: int}>
     */
    public function entries(): array
    {
        $entries = [];
        for ($entry = 0; $entry < $this->capacity; $entry++) {
            $word = $this->arena->readWord($this->address + $entry * 8);
            if ($word !== 0) {
                $entries[] = [
                    'entry' => $entry,
                    'slot'  => ($word & self::SLOT_MASK) - 1,
                    'pid'   => $word >> self::PID_SHIFT,
                    'word'  => $word,
                ];
            }
        }

        return $entries;
    }

    /**
     * The raw word at $entry, for a reaper re-verifying its scan under the lock
     */
    public function wordAt(int $entry): int
    {
        if ($entry < 0 || $entry >= $this->capacity) {
            return 0;
        }

        return $this->arena->readWord($this->address + $entry * 8);
    }
}
