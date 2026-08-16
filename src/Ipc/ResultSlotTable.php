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
 * notification socket with a fixed event record `{RESULT, slot ticket, tag, address}`. It then
 * reads the value straight out of shared memory. The socket never carries the value: for an
 * INT or FLOAT the event's address field is zero, and for a string, object or shared array
 * it holds an ADDRESS every process maps identically.
 *
 * ```text
 *   header (8 words)   capacity | next | mutex | format | free head | live | recycled | retired
 *   slot (12 words)    state | tag | payload | parked | generation | next free | 2 spare
 *                      | waiter table (4 entries)
 * ```
 *
 * A slot settles exactly once **per generation**: complete() and completePanic() refuse a slot
 * that is already DONE or PANIC, which is what makes "read the record after seeing the state" a
 * safe sequence rather than a race with a second writer.
 *
 * The same machinery carries spawn ARGUMENTS in the opposite direction: a slot completed by
 * the spawning process before the worker looks at it is a one-shot value handed downwards
 * with the identical tag contract - and it is released and recycled exactly like a result.
 *
 * ## Slots are recycled, and a generation is what makes that safe
 *
 * The arena is a bump allocator and **children never free arena memory**, so recycling a slot
 * can never mean giving its bytes back: the slot record is reused **in place**. What comes back
 * is the *right to use it*, tracked in a free list threaded through the slots themselves - one
 * header word for the head, one word per slot for the link, no allocation anywhere.
 *
 * The hazard recycling creates is that a slot id stops being a unique name. A handle held past
 * the moment its slot went back on the free list would address whatever task got the slot next,
 * and would be answered with that task's result - the exact silent-wrong-answer this package
 * exists to refuse. So an id is not an index: it is a {@see SlotTicket}, index and generation
 * packed into the 32 bits a {@see WakeEvent} carries, and **every verb checks the generation it
 * was handed against the one in the slot**. A mismatch is `IpcException`, naming the slot and
 * both generations. Reading, awaiting, completing and releasing all fail the same way.
 *
 * The generation moves at **release**, not at reuse: the moment an owner gives up its claim is
 * the moment the claim has to stop being answerable, and a slot that sits on the free list for a
 * while must not keep answering the handle that put it there. A generation is 16 bits and it
 * **never wraps** - a slot whose counter reaches {@see SlotTicket::MAX_GENERATION} is *retired*
 * (stamped generation 0 and left off the free list) rather than started over, so no handle can
 * ever be revived by a counter coming back round. Retirement costs one slot out of the supply
 * after 65 535 uses of it, which is the ordinary leak-until-teardown bargain of this arena.
 *
 * ## Who releases, and when
 *
 * {@see self::releaseSlot()} is the consumer's signal that a settled slot has been read by
 * everybody who was going to read it. This package does not guess at that: it refuses to
 * release a slot that has not settled ({@see IpcException::slotNotSettled()}), and otherwise
 * takes the caller at its word. The common case is one owner per slot - the process that
 * allocated it and awaits its result - and a second process that attaches to somebody else's
 * slot has to finish reading before the owner releases, or its next read fails loudly rather
 * than quietly returning the wrong task's answer.
 *
 * A slot nobody releases simply stays out of circulation, which is exactly the behaviour this
 * table had before recycling existed.
 */
final class ResultSlotTable
{
    public const string DEFAULT_ROOT = 'ipc.results';

    /**
     * Waiters per slot; a future normally has one owner, four is room for a select() fan-out
     */
    public const int SLOT_WAITERS = 4;

    /**
     * The record shape this build reads, checked at attach()
     *
     * `ResultSlotTable` is a consumer structure published in the roots directory, so it does not
     * ride `Registry::LAYOUT_VERSION` (AGENTS.md §7). This word is its own guard: a table written
     * by a build with a different slot geometry is refused instead of read at the right address
     * with the wrong meaning. Bump it in the same commit as any change to the words below.
     */
    public const int FORMAT = 2;

    private const float WAIT_SLICE = 0.05;

    private const int WORD_CAPACITY = 0;
    private const int WORD_NEXT     = 1;
    private const int WORD_MUTEX    = 2;
    private const int WORD_FORMAT   = 3;
    private const int WORD_FREE     = 4;
    private const int WORD_LIVE     = 5;
    private const int WORD_RECYCLED = 6;
    private const int WORD_RETIRED  = 7;
    private const int HEADER_WORDS  = 8;

    private const int SLOT_WORD_STATE      = 0;
    private const int SLOT_WORD_TAG        = 1;
    private const int SLOT_WORD_PAYLOAD    = 2;
    private const int SLOT_WORD_PARKED     = 3;
    private const int SLOT_WORD_GENERATION = 4;
    private const int SLOT_WORD_NEXT_FREE  = 5;
    private const int SLOT_WORD_WAITERS    = 8;
    private const int SLOT_WORDS           = 12;

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

        $format = (int) $this->arena->readWord($address + self::WORD_FORMAT * 8);
        if ($format !== self::FORMAT) {
            throw IpcException::slotTableFormat($format, self::FORMAT);
        }

        $this->capacity = (int) $this->arena->readWord($address + self::WORD_CAPACITY * 8);
        $this->mutex    = (int) $this->arena->readWord($address + self::WORD_MUTEX * 8);
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
        if ($capacity > SlotTicket::MAX_SLOTS) {
            throw IpcException::capacityTooLarge('Result slot table', $capacity, SlotTicket::MAX_SLOTS);
        }
        $arena   = $allocator->arena();
        $size    = (self::HEADER_WORDS + $capacity * self::SLOT_WORDS) * 8;
        $address = $arena->allocate($size, 64);
        $mutex   = $arena->allocateMutex();

        // Pre-sized means pre-sized. Slots are handed out one record at a time for the whole life
        // of the run, so without this the table's pages fault in a few dozen slots at a time and
        // the family's RSS climbs with the workload while the arena watermark - correctly - never
        // moves. That is indistinguishable from a leak to any memory gate, and it was reported as
        // one (native-php-coroutines#24). Creation happens before the fork, so the cost is paid
        // once. Recycling does not replace this: a steady workload reuses a handful of records and
        // would look flat by accident, while one whose concurrency really grows still reaches
        // records nothing has touched.
        $arena->prefault($address, $size);

        $arena->writeWord($address + self::WORD_CAPACITY * 8, $capacity);
        $arena->writeWord($address + self::WORD_MUTEX * 8, $mutex);
        $arena->writeWord($address + self::WORD_FORMAT * 8, self::FORMAT);

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
     * Slots handed out and not yet released - what a steady-state workload should plateau at
     *
     * Read without the lock on purpose: a single aligned 8-byte load never tears (EPIC #15,
     * correction #2), and a diagnostic that took the table mutex would serialize every reader
     * against every allocation for a number that is a snapshot either way.
     */
    public function outstanding(): int
    {
        return (int) $this->arena->readWord($this->address + self::WORD_LIVE * 8);
    }

    /**
     * Distinct slots the bump cursor has ever created - the table's high-water mark
     *
     * With recycling this is the number of slots a workload really needed at once. It is the
     * series a soak watches: consumption climbing means slots are not coming back.
     */
    public function highWaterMark(): int
    {
        return (int) $this->arena->readWord($this->address + self::WORD_NEXT * 8);
    }

    /**
     * How many times a released slot has been handed out again
     */
    public function recycled(): int
    {
        return (int) $this->arena->readWord($this->address + self::WORD_RECYCLED * 8);
    }

    /**
     * Slots taken out of circulation because their generation counter was used up
     */
    public function retired(): int
    {
        return (int) $this->arena->readWord($this->address + self::WORD_RETIRED * 8);
    }

    /**
     * Slots that could still be handed out: never-used ones plus everything on the free list
     */
    public function available(): int
    {
        return $this->capacity - $this->outstanding() - $this->retired();
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
     * Takes a slot and returns the ticket for it: the id that travels to whoever will complete it
     *
     * A recycled slot is preferred over a fresh one - the free list is a LIFO stack, so the slot
     * that came back most recently goes out first and a steady workload keeps reusing a handful of
     * records. Only when nothing is on the list does the bump cursor move, which is what makes the
     * high-water mark a real measure of concurrency rather than of throughput.
     */
    public function allocateSlot(): int
    {
        $recovered = $this->arena->lockMutexAt($this->mutex);

        $index      = $this->popFreeSlot();
        $generation = 0;

        if ($index === null) {
            $next = (int) $this->arena->readWord($this->address + self::WORD_NEXT * 8);
            if ($next < $this->capacity) {
                $index      = $next;
                $generation = 1;
                $this->arena->writeWord($this->address + self::WORD_NEXT * 8, $next + 1);
                $this->arena->writeWord($this->slotAt($index) + self::SLOT_WORD_GENERATION * 8, $generation);
            }
        } else {
            $generation = (int) $this->arena->readWord($this->slotAt($index) + self::SLOT_WORD_GENERATION * 8);
        }

        if ($index !== null) {
            $slot = $this->slotAt($index);
            $this->clearSlotRecord($slot);
            $this->arena->writeWord($slot + self::SLOT_WORD_STATE * 8, ResultState::Pending->value);
            $this->bumpHeader(self::WORD_LIVE, 1);
        }

        $outstanding = (int) $this->arena->readWord($this->address + self::WORD_LIVE * 8);
        $retired     = (int) $this->arena->readWord($this->address + self::WORD_RETIRED * 8);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($index === null) {
            throw IpcException::slotTableFull($this->capacity, $outstanding, $retired);
        }

        return SlotTicket::pack($index, $generation);
    }

    /**
     * Gives a settled, fully read slot back to the free list under a new generation
     *
     * The caller is asserting that nobody will read this slot through this ticket again. That is
     * the one thing this table cannot check for itself - a waiter in another process is invisible
     * from here - so the guarantee is made from the other end instead: the generation moves now, so
     * anybody who *does* come back is refused by name rather than answered with the next task's
     * result.
     *
     * The whole slot record is wiped, waiter table included. A process still parked inside
     * {@see self::await()} on the old generation therefore finds nothing of its own left; it fails
     * the generation check on its next pass and never writes into the recycled record, because
     * unparking re-checks the generation under the same lock.
     */
    public function releaseSlot(int $ticket): void
    {
        $slot  = $this->slotAddress($ticket);
        $index = SlotTicket::indexOf($ticket);
        $want  = SlotTicket::generationOf($ticket);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $current = (int) $this->arena->readWord($slot + self::SLOT_WORD_GENERATION * 8);
        $state   = (int) $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8);
        $stale   = $current !== $want;
        $settled = $state === ResultState::Done->value || $state === ResultState::Panic->value;

        if (!$stale && $settled) {
            $this->recycle($index, $slot, $current);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($stale) {
            throw $this->staleFailure($index, $want, $current);
        }
        if (!$settled) {
            throw IpcException::slotNotSettled($index);
        }
    }

    /**
     * Settles a slot with a value and wakes whoever is awaiting it
     */
    public function complete(int $ticket, mixed $value): void
    {
        // Encoded before the lock: interning a string allocates arena memory and a
        // non-shareable value must throw without a lock ever being taken
        [$tag, $payload] = $this->codec->encode($value);

        $this->settle($ticket, ResultState::Done, $tag, $payload, WakeOpcode::Result);
    }

    /**
     * Settles a slot with the address of a shared error-info object (see SharedError)
     */
    public function completePanic(int $ticket, int $errorAddress): void
    {
        if (!$this->arena->contains($errorAddress, 8)) {
            throw NotShareableValueException::foreignAddress($errorAddress);
        }

        $this->settle($ticket, ResultState::Panic, ValueTag::Obj, $errorAddress, WakeOpcode::Panic);
    }

    /**
     * Reads a slot as it stands right now, without waiting
     */
    public function readSlot(int $ticket): SlotResult
    {
        $slot  = $this->slotAddress($ticket);
        $index = SlotTicket::indexOf($ticket);
        $want  = SlotTicket::generationOf($ticket);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $current = (int) $this->arena->readWord($slot + self::SLOT_WORD_GENERATION * 8);
        $stale   = $current !== $want;
        $state   = (int) $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8);
        $tag     = (int) $this->arena->readWord($slot + self::SLOT_WORD_TAG * 8);
        $payload = (int) $this->arena->readWord($slot + self::SLOT_WORD_PAYLOAD * 8);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($stale) {
            throw $this->staleFailure($index, $want, $current);
        }

        $state = ResultState::from($state);
        if ($state === ResultState::Pending) {
            return new SlotResult($ticket, $state);
        }
        $valueTag = ValueTag::from($tag);

        // Materialized outside the lock: attaching an object registers it in this request's
        // object store, which is an engine call that allocates
        return new SlotResult($ticket, $state, $this->codec->decode($valueTag, $payload), $valueTag);
    }

    /**
     * Parks until a slot is settled (or the deadline passes, which returns it still pending)
     *
     * @param float|null $timeout Seconds to wait; null waits forever
     */
    public function await(int $ticket, ?float $timeout = null): SlotResult
    {
        $slot     = $this->slotAddress($ticket);
        $index    = SlotTicket::indexOf($ticket);
        $want     = SlotTicket::generationOf($ticket);
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $wakeSlot = $this->wake->slot();
        $waiters  = new WaiterTable($this->arena, $slot + self::SLOT_WORD_WAITERS * 8, self::SLOT_WAITERS);

        while (true) {
            $result = $this->readSlot($ticket);
            if (!$result->isPending()) {
                return $result;
            }

            $recovered = $this->arena->lockMutexAt($this->mutex);

            $current = (int) $this->arena->readWord($slot + self::SLOT_WORD_GENERATION * 8);
            $stale   = $current !== $want;
            $settled = $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8) !== ResultState::Pending->value;
            $entry   = null;
            if (!$stale && !$settled) {
                // Registered and re-checked under ONE lock: a completion after this point
                // necessarily sees the entry, so no wakeup can be lost
                $entry = $waiters->register($wakeSlot);
                $this->bumpParked($slot, 1);
            }

            $this->arena->unlockMutexAt($this->mutex);

            $this->recoveredLock = $this->recoveredLock || $recovered;

            if ($stale) {
                throw $this->staleFailure($index, $want, $current);
            }

            if (!$settled) {
                if ($deadline === null) {
                    $this->wake->wait(self::WAIT_SLICE);
                } else {
                    $remaining = $deadline - microtime(true);
                    if ($remaining > 0) {
                        $this->wake->wait(min($remaining, self::WAIT_SLICE));
                    }
                }
                $this->unpark($slot, $want, $waiters, $entry);
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return $this->readSlot($ticket);
            }
        }
    }

    /**
     * Writes the record, flips the state and notifies the parked waiters
     */
    private function settle(int $ticket, ResultState $state, ValueTag $tag, int $payload, WakeOpcode $opcode): void
    {
        $slot    = $this->slotAddress($ticket);
        $index   = SlotTicket::indexOf($ticket);
        $want    = SlotTicket::generationOf($ticket);
        $waiters = new WaiterTable($this->arena, $slot + self::SLOT_WORD_WAITERS * 8, self::SLOT_WAITERS);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $current = (int) $this->arena->readWord($slot + self::SLOT_WORD_GENERATION * 8);
        $stale   = $current !== $want;
        $settled = $this->arena->readWord($slot + self::SLOT_WORD_STATE * 8) !== ResultState::Pending->value;
        if (!$stale && !$settled) {
            $this->arena->writeWord($slot + self::SLOT_WORD_TAG * 8, $tag->value);
            $this->arena->writeWord($slot + self::SLOT_WORD_PAYLOAD * 8, $payload);
            // State last: a reader that sees a settled state is guaranteed to see the
            // record that goes with it, and readers take this same lock anyway
            $this->arena->writeWord($slot + self::SLOT_WORD_STATE * 8, $state->value);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        // Thrown outside the critical section on purpose: an exception raised while the robust
        // mutex is held would leave it owned by a frame that is unwinding
        if ($stale) {
            throw $this->staleFailure($index, $want, $current);
        }
        if ($settled) {
            throw IpcException::slotAlreadyCompleted($index, $want);
        }

        $this->wake->notifyAll($waiters->occupants(), WakeEvent::forValue($opcode, $ticket, $tag, $payload));
    }

    /**
     * Wipes a settled slot and puts it back in circulation; the caller holds the table lock
     *
     * Nothing is freed here and nothing can be: the arena is bump-only and a child may never
     * free (AGENTS.md §2, §6). The record is reused where it lies, which is the only shape
     * recycling can have in this allocator.
     */
    private function recycle(int $index, int $slot, int $generation): void
    {
        $this->clearSlotRecord($slot);
        $this->arena->writeWord($slot + self::SLOT_WORD_STATE * 8, ResultState::Free->value);
        $this->bumpHeader(self::WORD_LIVE, -1);

        if ($generation >= SlotTicket::MAX_GENERATION) {
            // Retired rather than wrapped: a counter coming back round would make a handle from
            // 65 535 generations ago match again, and this design has no second line of defence
            // behind the generation check
            $this->arena->writeWord($slot + self::SLOT_WORD_GENERATION * 8, SlotTicket::RETIRED);
            $this->bumpHeader(self::WORD_RETIRED, 1);

            return;
        }

        $this->arena->writeWord($slot + self::SLOT_WORD_GENERATION * 8, $generation + 1);
        $this->pushFreeSlot($index, $slot);
    }

    /**
     * Takes the most recently released slot off the free list; the caller holds the table lock
     *
     * @return int|null Slot index, or null when nothing has been released yet
     */
    private function popFreeSlot(): ?int
    {
        $head = (int) $this->arena->readWord($this->address + self::WORD_FREE * 8);
        if ($head === 0) {
            return null;
        }

        $index = $head - 1;
        $slot  = $this->slotAt($index);

        $this->arena->writeWord(
            $this->address + self::WORD_FREE * 8,
            (int) $this->arena->readWord($slot + self::SLOT_WORD_NEXT_FREE * 8),
        );
        $this->arena->writeWord($slot + self::SLOT_WORD_NEXT_FREE * 8, 0);
        $this->bumpHeader(self::WORD_RECYCLED, 1);

        return $index;
    }

    /**
     * Pushes a slot onto the free list; the caller holds the table lock
     *
     * The link is `index + 1` so that a zero word means "end of list" without a separate flag,
     * the same trick {@see WaiterTable} uses for occupancy.
     */
    private function pushFreeSlot(int $index, int $slot): void
    {
        $this->arena->writeWord(
            $slot + self::SLOT_WORD_NEXT_FREE * 8,
            (int) $this->arena->readWord($this->address + self::WORD_FREE * 8),
        );
        $this->arena->writeWord($this->address + self::WORD_FREE * 8, $index + 1);
    }

    /**
     * Zeroes everything about a slot except its state and generation; the caller holds the lock
     *
     * The waiter table goes too. Leaving one entry of a past generation behind would cost the
     * next owner of the slot a spurious notification at best, and at worst a waiter table that
     * is permanently full of processes that stopped caring several tasks ago.
     */
    private function clearSlotRecord(int $slot): void
    {
        $this->arena->writeWord($slot + self::SLOT_WORD_TAG * 8, 0);
        $this->arena->writeWord($slot + self::SLOT_WORD_PAYLOAD * 8, 0);
        $this->arena->writeWord($slot + self::SLOT_WORD_PARKED * 8, 0);
        $this->arena->writeWord($slot + self::SLOT_WORD_NEXT_FREE * 8, 0);

        for ($entry = 0; $entry < self::SLOT_WAITERS; $entry++) {
            $this->arena->writeWord($slot + (self::SLOT_WORD_WAITERS + $entry) * 8, 0);
        }
    }

    private function unpark(int $slot, int $generation, WaiterTable $waiters, ?int $entry): void
    {
        if ($entry === null) {
            return;
        }

        $recovered = $this->arena->lockMutexAt($this->mutex);

        // Only while the slot is still the one this process parked on: a slot recycled underneath
        // a parked waiter belongs to another task now, and releasing "our" entry in it would cost
        // that task a wakeup it is entitled to
        if ((int) $this->arena->readWord($slot + self::SLOT_WORD_GENERATION * 8) === $generation) {
            $waiters->release($entry);
            $this->bumpParked($slot, -1);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Moves a slot's parked counter; the caller holds the table lock
     */
    private function bumpParked(int $slot, int $delta): void
    {
        $parked = (int) $this->arena->readWord($slot + self::SLOT_WORD_PARKED * 8) + $delta;
        $this->arena->writeWord($slot + self::SLOT_WORD_PARKED * 8, max($parked, 0));
    }

    /**
     * Moves a header counter; the caller holds the table lock
     */
    private function bumpHeader(int $word, int $delta): void
    {
        $value = (int) $this->arena->readWord($this->address + $word * 8) + $delta;
        $this->arena->writeWord($this->address + $word * 8, max($value, 0));
    }

    /**
     * The refusal for a handle whose generation is not the slot's, told apart from a dead slot
     */
    private function staleFailure(int $index, int $held, int $current): IpcException
    {
        return $current === SlotTicket::RETIRED
            ? IpcException::slotRetired($index)
            : IpcException::staleSlot($index, $held, $current);
    }

    /**
     * Where a ticket's slot record starts, refusing an index the table does not have
     *
     * Called before the lock is taken, never under it: it is the one part of addressing a slot
     * that can throw.
     */
    private function slotAddress(int $ticket): int
    {
        $index = SlotTicket::indexOf($ticket);
        if ($index < 0 || $index >= $this->capacity) {
            throw IpcException::unknownSlot($index, $this->capacity);
        }

        return $this->slotAt($index);
    }

    /**
     * Address arithmetic for a slot index already known to be in range
     */
    private function slotAt(int $index): int
    {
        return $this->address + (self::HEADER_WORDS + $index * self::SLOT_WORDS) * 8;
    }
}
