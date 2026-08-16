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
use Lisachenko\SharedData\Shm\ArenaAllocator;

/**
 * Futures over the shared area: a table of slots a coroutine's return value lands in
 *
 * This is the piece the runtime model of EPIC #15 is built on. A coroutine finishing
 * ANYWHERE in the process tree writes its return value into its slot as a 16-byte record,
 * and the process waiting for it - very likely a different one - is told over its
 * notification socket with a fixed event record `{RESULT, slot id, tag, address}`. It then
 * reads the value straight out of shared memory. The socket never carries the value: for an
 * INT or FLOAT the event's address field is zero, and for a string, object or shared array
 * it holds an ADDRESS every process maps identically.
 *
 * ```text
 *   header (4 words)   capacity | next slot | mutex address | reserved
 *   slot (8 words)     state | tag | payload | waiters parked | waiter table (4 entries)
 * ```
 *
 * A slot settles exactly once: complete() and completePanic() refuse a slot that is already
 * DONE or PANIC, which is what makes "read the record after seeing the state" a safe
 * sequence rather than a race with a second writer. Slots are handed out by a bump counter
 * and never recycled in v1 - the table is pre-sized before the fork, like everything else in
 * the arena, and exhausting it is a typed failure that names the knob.
 *
 * The same machinery carries spawn ARGUMENTS in the opposite direction: a slot completed by
 * the spawning process before the worker looks at it is a one-shot value handed downwards
 * with the identical tag contract.
 */
final class ResultSlotTable
{
    public const string DEFAULT_ROOT = 'ipc.results';

    /**
     * Waiters per slot; a future normally has one owner, four is room for a select() fan-out
     */
    public const int SLOT_WAITERS = 4;

    private const float WAIT_SLICE = 0.05;

    private const int WORD_CAPACITY = 0;
    private const int WORD_NEXT     = 1;
    private const int WORD_MUTEX    = 2;
    private const int HEADER_WORDS  = 4;

    private const int SLOT_WORD_STATE   = 0;
    private const int SLOT_WORD_TAG     = 1;
    private const int SLOT_WORD_PAYLOAD = 2;
    private const int SLOT_WORD_PARKED  = 3;
    private const int SLOT_WORD_WAITERS = 4;
    private const int SLOT_WORDS        = 8;

    private readonly Arena $arena;

    private readonly int $capacity;

    private readonly int $mutex;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly ArenaAllocator $allocator,
        private readonly ValueCodec $codec,
        private readonly WakeRegistry $wake,
        private readonly int $address,
    ) {
        $this->arena = $allocator->arena();
        if (!$this->arena->contains($address, self::HEADER_WORDS * 8)) {
            throw IpcException::notShared('result slot table', $address);
        }
        $this->capacity = $this->arena->readWord($address + self::WORD_CAPACITY * 8);
        $this->mutex    = $this->arena->readWord($address + self::WORD_MUTEX * 8);
    }

    /**
     * Creates the table in the arena; do this before the workers fork
     */
    public static function create(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        int $capacity,
        ?string $name = self::DEFAULT_ROOT,
    ): self {
        if ($capacity <= 0) {
            throw IpcException::invalidCapacity('Result slot table', $capacity);
        }
        $arena   = $allocator->arena();
        $size    = (self::HEADER_WORDS + $capacity * self::SLOT_WORDS) * 8;
        $address = $arena->allocate($size, 64);
        $mutex   = $arena->allocateMutex();

        // Pre-sized means pre-sized. Slots are handed out one at a time for the whole life of the
        // run, so without this the table's pages fault in one per eight slots and the family's RSS
        // climbs with the workload while the arena watermark - correctly - never moves. That is
        // indistinguishable from a leak to any memory gate, and it was reported as one
        // (native-php-coroutines#24). Creation happens before the fork, so the cost is paid once.
        $arena->prefault($address, $size);

        $arena->writeWord($address + self::WORD_CAPACITY * 8, $capacity);
        $arena->writeWord($address + self::WORD_MUTEX * 8, $mutex);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($allocator, $codec, $wake, $address);
    }

    public static function attach(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        int $address,
    ): self {
        return new self($allocator, $codec, $wake, $address);
    }

    /**
     * Binds a table published in the arena roots directory
     */
    public static function open(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        string $name = self::DEFAULT_ROOT,
    ): self {
        return new self($allocator, $codec, $wake, $allocator->arena()->requireRoot($name));
    }

    public function address(): int
    {
        return $this->address;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * The descriptor a scheduler parks on while awaiting slots
     *
     * @return resource
     */
    public function notificationStream()
    {
        return $this->wake->stream();
    }

    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * Takes the next free slot; the id is what travels to whoever will complete it
     */
    public function allocateSlot(): int
    {
        $recovered = $this->arena->lockMutexAt($this->mutex);

        $next   = $this->arena->readWord($this->address + self::WORD_NEXT * 8);
        $fits   = $next < $this->capacity;
        if ($fits) {
            $this->arena->writeWord($this->address + self::WORD_NEXT * 8, $next + 1);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if (!$fits) {
            throw IpcException::slotTableFull($this->capacity);
        }

        return $next;
    }

    /**
     * Settles a slot with a value and wakes whoever is awaiting it
     */
    public function complete(int $id, mixed $value): void
    {
        // Encoded before the lock: interning a string allocates arena memory and a
        // non-shareable value must throw without a lock ever being taken
        [$tag, $payload] = $this->codec->encode($value);

        $this->settle($id, ResultState::Done, $tag, $payload, WakeOpcode::Result);
    }

    /**
     * Settles a slot with the address of a shared error-info object (see SharedError)
     */
    public function completePanic(int $id, int $errorAddress): void
    {
        if (!$this->arena->contains($errorAddress, 8)) {
            throw NotShareableValueException::foreignAddress($errorAddress);
        }

        $this->settle($id, ResultState::Panic, ValueTag::Obj, $errorAddress, WakeOpcode::Panic);
    }

    /**
     * Reads a slot as it stands right now, without waiting
     */
    public function readSlot(int $id): SlotResult
    {
        $slot = $this->slotAddress($id);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $state   = $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8);
        $tag     = $this->arena->readWord($slot + self::SLOT_WORD_TAG * 8);
        $payload = $this->arena->readWord($slot + self::SLOT_WORD_PAYLOAD * 8);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        $state = ResultState::from($state);
        if ($state === ResultState::Pending) {
            return new SlotResult($id, $state);
        }
        $valueTag = ValueTag::from($tag);

        // Materialized outside the lock: attaching an object registers it in this request's
        // object store, which is an engine call that allocates
        return new SlotResult($id, $state, $this->codec->decode($valueTag, $payload), $valueTag);
    }

    /**
     * Parks until a slot is settled (or the deadline passes, which returns it still pending)
     *
     * @param float|null $timeout Seconds to wait; null waits forever
     */
    public function await(int $id, ?float $timeout = null): SlotResult
    {
        $slot     = $this->slotAddress($id);
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $wakeSlot = $this->wake->slot();
        $waiters  = new WaiterTable($this->arena, $slot + self::SLOT_WORD_WAITERS * 8, self::SLOT_WAITERS);

        while (true) {
            $result = $this->readSlot($id);
            if (!$result->isPending()) {
                return $result;
            }

            $recovered = $this->arena->lockMutexAt($this->mutex);

            $settled = $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8) !== ResultState::Pending->value;
            $entry   = null;
            if (!$settled) {
                // Registered and re-checked under ONE lock: a completion after this point
                // necessarily sees the entry, so no wakeup can be lost
                $entry = $waiters->register($wakeSlot);
                $this->bumpParked($slot, 1);
            }

            $this->arena->unlockMutexAt($this->mutex);

            $this->recoveredLock = $this->recoveredLock || $recovered;

            if (!$settled) {
                if ($deadline === null) {
                    $this->wake->wait(self::WAIT_SLICE);
                } else {
                    $remaining = $deadline - microtime(true);
                    if ($remaining > 0) {
                        $this->wake->wait(min($remaining, self::WAIT_SLICE));
                    }
                }
                $this->unpark($slot, $waiters, $entry);
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return $this->readSlot($id);
            }
        }
    }

    /**
     * Writes the record, flips the state and notifies the parked waiters
     */
    private function settle(int $id, ResultState $state, ValueTag $tag, int $payload, WakeOpcode $opcode): void
    {
        $slot    = $this->slotAddress($id);
        $waiters = new WaiterTable($this->arena, $slot + self::SLOT_WORD_WAITERS * 8, self::SLOT_WAITERS);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $settled = $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8) !== ResultState::Pending->value;
        if (!$settled) {
            $this->arena->writeWord($slot + self::SLOT_WORD_TAG * 8, $tag->value);
            $this->arena->writeWord($slot + self::SLOT_WORD_PAYLOAD * 8, $payload);
            // State last: a reader that sees a settled state is guaranteed to see the
            // record that goes with it, and readers take this same lock anyway
            $this->arena->writeWord($slot + self::SLOT_WORD_STATE * 8, $state->value);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($settled) {
            throw IpcException::slotAlreadyCompleted($id);
        }

        $this->wake->notifyAll($waiters->occupants(), WakeEvent::forValue($opcode, $id, $tag, $payload));
    }

    private function unpark(int $slot, WaiterTable $waiters, ?int $entry): void
    {
        if ($entry === null) {
            return;
        }

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $waiters->release($entry);
        $this->bumpParked($slot, -1);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Moves a slot's parked counter; the caller holds the table lock
     */
    private function bumpParked(int $slot, int $delta): void
    {
        $parked = $this->arena->readWord($slot + self::SLOT_WORD_PARKED * 8) + $delta;
        $this->arena->writeWord($slot + self::SLOT_WORD_PARKED * 8, max($parked, 0));
    }

    private function slotAddress(int $id): int
    {
        if ($id < 0 || $id >= $this->capacity) {
            throw IpcException::unknownSlot($id, $this->capacity);
        }

        return $this->address + (self::HEADER_WORDS + $id * self::SLOT_WORDS) * 8;
    }
}
