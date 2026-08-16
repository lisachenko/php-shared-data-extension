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
 * A Go-style channel whose ring, waiters and close flag all live in the shared arena
 *
 * Nothing about a channel is per-process except the descriptors it wakes people through: the
 * ring of 16-byte records, the head and tail counters, the closed flag and the two waiter
 * tables are arena memory, so a producer in one worker and a consumer in another operate on
 * one structure rather than on two views that have to be reconciled. Values never leave the
 * shared area on the way: send() writes a record, recv() reads one, and the socket in between
 * carries a fixed wake record with no payload in it (see WakeEvent).
 *
 * ```text
 *   header (8 words)   capacity | head | tail | closed | mutex address |
 *                      waiter capacity | receivers parked | senders parked
 *   receivers table    waiter capacity words - wake slots of parked receivers
 *   senders table      waiter capacity words - wake slots of parked senders
 *   ring               max(capacity, 1) records of 16 bytes
 * ```
 *
 * `head` and `tail` are MONOTONIC counters, never wrapped indexes: `tail - head` is the fill
 * level, the slot is `counter % slots`, and a sender that deposited at ticket N knows its
 * record was taken the moment `head > N`. That is what makes the rendezvous handshake a
 * single word comparison instead of a state machine.
 *
 * ## One dedicated mutex per channel, and the whole ring op under it
 *
 * The lock is a robust process-shared mutex of the channel's OWN (Arena::allocateMutex())
 * rather than a stripe from the bank: a channel takes its lock on every single operation, and
 * two busy channels sharing a stripe would serialize against each other for no reason. The
 * entire ring operation happens inside it - a 16-byte record store is not atomic (EPIC #15,
 * correction #1), and a publish-payload-then-tag protocol would still leave the counters
 * racing. Inside the critical section there is nothing but aligned word loads and stores:
 * values are encoded before the lock is taken and decoded after it is released.
 *
 * ## Blocking here is a spin loop, not a scheduler
 *
 * send() and recv() park on the notification socket with a bounded slice and re-poll, which
 * is the honest primitive a package with no scheduler can offer. A coroutine runtime wants
 * the other half of the API: trySend()/tryRecv() plus notificationStream(), so it can park a
 * Fiber in its own event loop and never block the process. Both halves observe the same
 * waiter tables, so a Fiber-parked consumer and a spin-blocked one wake identically.
 *
 * ## Rendezvous with a receiver that is parked somewhere else
 *
 * The gate on a capacity-0 handoff is "is a receiver waiting", and until registerReceiver()
 * existed the only way to be one was to be inside recv() - which a scheduler-driven consumer
 * never calls, because its whole invariant is that a worker blocks in exactly one place. That
 * made a rendezvous unusable from a coroutine runtime rather than merely inconvenient, so the
 * registration is now a named operation of its own:
 *
 * ```php
 * $token = $channel->registerReceiver();          // null => a record is already there
 * // ... park the Fiber on notificationStream() in the consumer's own event loop ...
 * $channel->cancelReceiver($token);               // on unpark, whatever woke it
 * ```
 *
 * A registration is a claim about presence, never about storage: the handed-off record still
 * goes into the one ring slot a capacity-0 channel allocates (`max($capacity, 1)`), so there
 * is no per-registration cell to keep consistent and no way for a value to belong to a waiter
 * that walked away. That is what makes cancellation total - cancelReceiver() can always
 * succeed, because it never has a value in its hands. A record deposited against a
 * registration that is cancelled a moment later simply stays in the ring for the next
 * receiver, and the sender stays parked until somebody actually takes it, which is exactly
 * the state it would have been in had it never deposited at all.
 *
 * Registrations outlive the call that made them, so unlike a waiter parked inside recv() they
 * can outlive their process. Each entry records its owner pid; a rendezvous deposit reaps the
 * ones whose owner is gone before it reads the gate (see reapDeadWaiters()), so a dead
 * worker's registration cannot go on telling senders that a partner is present.
 */
final class SharedChannel
{
    public const int DEFAULT_WAITERS = 16;

    /**
     * Longest a blocking call sleeps before it re-polls the shared state on its own
     *
     * A wake event is an optimization, never a correctness requirement: a dropped one (full
     * socket buffer) costs at most this slice of latency.
     */
    private const float WAIT_SLICE = 0.05;

    private const int WORD_CAPACITY         = 0;
    private const int WORD_HEAD             = 1;
    private const int WORD_TAIL             = 2;
    private const int WORD_CLOSED           = 3;
    private const int WORD_MUTEX            = 4;
    private const int WORD_WAITER_CAPACITY  = 5;
    private const int WORD_RECEIVERS_PARKED = 6;
    private const int WORD_SENDERS_PARKED   = 7;
    private const int HEADER_WORDS          = 8;

    private readonly Arena $arena;

    private readonly int $mutex;

    private readonly int $capacity;

    private readonly int $waiterCapacity;

    private readonly WaiterTable $receivers;

    private readonly WaiterTable $senders;

    private readonly int $ringAddress;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly ArenaAllocator $allocator,
        private readonly ValueCodec $codec,
        private readonly WakeRegistry $wake,
        private readonly int $address,
    ) {
        $this->arena = $allocator->arena();
        if (!$this->arena->contains($address, self::HEADER_WORDS * 8)) {
            throw IpcException::notShared('channel', $address);
        }

        $this->capacity       = $this->word(self::WORD_CAPACITY);
        $this->mutex          = $this->word(self::WORD_MUTEX);
        $this->waiterCapacity = $this->word(self::WORD_WAITER_CAPACITY);

        $tables          = $this->address + self::HEADER_WORDS * 8;
        $this->receivers = new WaiterTable($this->arena, $tables, $this->waiterCapacity);
        $this->senders   = new WaiterTable(
            $this->arena,
            $tables + WaiterTable::bytesFor($this->waiterCapacity),
            $this->waiterCapacity,
        );
        $this->ringAddress = $tables + 2 * WaiterTable::bytesFor($this->waiterCapacity);
    }

    /**
     * Creates a channel in the arena and optionally publishes it under a name
     *
     * @param int         $capacity Buffered records; 0 makes it a rendezvous channel, where a
     *                              send only completes once a receiver has taken the value
     * @param string|null $name     Roots-directory name siblings can find the channel by
     */
    public static function create(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        int $capacity,
        int $waiterCapacity = self::DEFAULT_WAITERS,
        ?string $name = null,
    ): self {
        if ($capacity < 0) {
            throw IpcException::invalidCapacity('Channel', $capacity);
        }
        if ($waiterCapacity <= 0) {
            throw IpcException::invalidCapacity('Channel waiter table', $waiterCapacity);
        }

        $arena = $allocator->arena();
        $slots = max($capacity, 1);
        $bytes = self::HEADER_WORDS * 8
            + 2 * WaiterTable::bytesFor($waiterCapacity)
            + $slots * ValueRecord::SIZE;

        $address = $arena->allocate($bytes, 64);
        $mutex   = $arena->allocateMutex();

        // Fresh arena memory is zero-filled by the kernel, so head, tail, the closed flag,
        // both waiter tables and every ring record already read as empty
        $arena->writeWord($address + self::WORD_CAPACITY * 8, $capacity);
        $arena->writeWord($address + self::WORD_MUTEX * 8, $mutex);
        $arena->writeWord($address + self::WORD_WAITER_CAPACITY * 8, $waiterCapacity);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($allocator, $codec, $wake, $address);
    }

    /**
     * Binds a channel another process created, by the address it published
     */
    public static function attach(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        int $address,
    ): self {
        return new self($allocator, $codec, $wake, $address);
    }

    /**
     * Binds a channel published in the arena roots directory
     */
    public static function open(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        WakeRegistry $wake,
        string $name,
    ): self {
        return new self($allocator, $codec, $wake, $allocator->arena()->requireRoot($name));
    }

    /**
     * Address of the channel header - the eight bytes that identify it between processes
     */
    public function address(): int
    {
        return $this->address;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function isRendezvous(): bool
    {
        return $this->capacity === 0;
    }

    /**
     * Records currently buffered (single-word reads, so no lock and no tearing)
     */
    public function count(): int
    {
        return $this->word(self::WORD_TAIL) - $this->word(self::WORD_HEAD);
    }

    public function isClosed(): bool
    {
        return $this->word(self::WORD_CLOSED) !== 0;
    }

    /**
     * Receivers currently waiting on this channel, parked inside recv() or registered
     *
     * On a rendezvous channel this is the gate a handoff passes: a non-zero count is what
     * makes trySend() accept a value. It is a hint for everybody else - by the time a caller
     * reads it, a waiter may have taken a record or cancelled.
     */
    public function parkedReceivers(): int
    {
        return $this->word(self::WORD_RECEIVERS_PARKED);
    }

    /**
     * Senders currently waiting on this channel, parked inside send() or registered
     */
    public function parkedSenders(): int
    {
        return $this->word(self::WORD_SENDERS_PARKED);
    }

    /**
     * The descriptor a scheduler parks on; drain it and re-poll tryRecv()/trySend()
     *
     * @return resource
     */
    public function notificationStream()
    {
        return $this->wake->stream();
    }

    /**
     * Whether a lock of this channel was ever recovered from a worker that died holding it
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * Sends without ever blocking; false means "no room right now"
     *
     * On a rendezvous channel this succeeds only while a receiver is waiting - one parked
     * inside recv() or one that announced itself with registerReceiver() - and it returns as
     * soon as the record is deposited. The synchronous half of the handshake (waiting until
     * the value is actually taken) is what send() adds on top, and what a consumer with its
     * own scheduler builds out of trySendTicket() and isTicketTaken().
     */
    public function trySend(mixed $value): bool
    {
        return $this->trySendTicket($value) !== null;
    }

    /**
     * trySend(), returning the ticket the record was deposited at instead of a flag
     *
     * The ticket is the monotonic position in the ring, and `head > ticket` is the whole
     * definition of "this record has been taken" - see isTicketTaken(). A consumer that parks
     * its own waiters needs exactly that: trySend() alone cannot express a rendezvous, because
     * the deposit and the take are two events and only the second one completes the handshake.
     *
     * @return int|null Ticket of the deposited record, or null when it could not be deposited
     */
    public function trySendTicket(mixed $value): ?int
    {
        [$tag, $payload] = $this->codec->encode($value);

        $ticket = $this->offer($tag, $payload, requireParkedReceiver: $this->isRendezvous());
        if ($ticket === null) {
            return null;
        }
        $this->wakeReceivers($tag, $payload);

        return $ticket;
    }

    /**
     * Whether the record deposited at $ticket has been taken by a receiver
     *
     * A single aligned word read of a monotonic counter, so no lock: head only ever grows, and
     * an 8-byte load never tears (EPIC #15, correction #2).
     */
    public function isTicketTaken(int $ticket): bool
    {
        return $this->word(self::WORD_HEAD) > $ticket;
    }

    /**
     * Sends, waiting for room (and, on a rendezvous channel, for a receiver to take the value)
     *
     * @param float|null $timeout Seconds to wait; null waits forever
     *
     * @return bool Whether the value was sent
     */
    public function send(mixed $value, ?float $timeout = null): bool
    {
        // Encoded once, outside every critical section: interning a string allocates arena
        // memory, and a rejected value must throw before any lock is taken
        [$tag, $payload] = $this->codec->encode($value);
        $deadline        = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $ticket = $this->offer($tag, $payload, requireParkedReceiver: false);
            if ($ticket !== null) {
                $this->wakeReceivers($tag, $payload);

                return $this->isRendezvous() ? $this->awaitTaken($ticket, $deadline) : true;
            }
            if (!$this->parkForRoom($deadline)) {
                return false;
            }
        }
    }

    /**
     * Takes a record if one is buffered
     *
     * @return array{0: mixed, 1: bool}|null The value and true, [null, false] on a drained
     *                                       closed channel, or null when nothing is ready
     */
    public function tryRecv(): ?array
    {
        $recovered = $this->arena->lockMutexAt($this->mutex);

        $head   = $this->word(self::WORD_HEAD);
        $tail   = $this->word(self::WORD_TAIL);
        $closed = $this->word(self::WORD_CLOSED);
        $tag    = ValueTag::Nil->value;
        $load   = 0;
        $took   = $tail > $head;
        if ($took) {
            $slot = $this->slotAddress($head);
            $tag  = $this->arena->readWord($slot);
            $load = $this->arena->readWord($slot + 8);
            $this->setWord(self::WORD_HEAD, $head + 1);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if (!$took) {
            return $closed !== 0 ? [null, false] : null;
        }

        // A slot became free: senders parked on a full ring (or on a rendezvous handshake)
        // are told after the lock is gone, never while holding it
        $this->wakeSenders();

        return [$this->codec->decode(ValueTag::from($tag), $load), true];
    }

    /**
     * Receives, waiting for a record to arrive
     *
     * @param float|null $timeout Seconds to wait; null waits forever
     *
     * @return array{0: mixed, 1: bool} [value, true], or [null, false] once a closed channel
     *                                  has been drained (and on timeout)
     */
    public function recv(?float $timeout = null): array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $received = $this->tryRecv();
            if ($received !== null) {
                return $received;
            }
            if (!$this->parkForRecord($deadline)) {
                return [null, false];
            }
        }
    }

    /**
     * Closes the channel for every process, then wakes everybody parked on it
     *
     * Buffered records survive: receivers drain them and only then see the end of stream.
     */
    public function close(): void
    {
        $recovered = $this->arena->lockMutexAt($this->mutex);

        $this->setWord(self::WORD_CLOSED, 1);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        $event = new WakeEvent(WakeOpcode::Close, $this->channelId(), ValueTag::Close);
        $this->wake->notifyAll($this->receivers->occupants(), $event);
        $this->wake->notifyAll($this->senders->occupants(), $event);
    }

    /**
     * Announces a receiver that is parked somewhere other than inside recv()
     *
     * This is the half of the rendezvous handshake a consumer with its own scheduler could not
     * express before: it makes the channel count this process as a waiting receiver, so a
     * sibling's trySend() on a capacity-0 channel has a partner to hand its value to, while
     * the Fiber that will take the value sits in the consumer's own event loop on
     * notificationStream().
     *
     * The registration and the readiness re-check happen in ONE critical section, which is the
     * only thing that makes the wakeup safe: a sender that deposits after this point
     * necessarily sees this entry, and a record that arrived before it is reported here rather
     * than waited for. A caller that gets null must NOT park - it retries tryRecv() at once.
     *
     * @return int|null Token for cancelReceiver(), or null when a record (or a close) is
     *                  already there and nothing was registered
     *
     * @throws IpcException When every entry of the receivers table is taken
     */
    public function registerReceiver(): ?int
    {
        // Claimed before the lock: slot() may take the wake registry's own mutex, and two
        // arena locks held at once is a lock order nobody else in this package obeys
        $wakeSlot = $this->wake->slot();
        $owner    = (int) getmypid();

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $ready = $this->word(self::WORD_TAIL) > $this->word(self::WORD_HEAD)
            || $this->word(self::WORD_CLOSED) !== 0;
        $entry = null;
        if (!$ready) {
            $entry = $this->receivers->register($wakeSlot, $owner);
            if ($entry !== null) {
                $this->setWord(self::WORD_RECEIVERS_PARKED, $this->word(self::WORD_RECEIVERS_PARKED) + 1);
            }
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($ready) {
            return null;
        }
        if ($entry === null) {
            throw IpcException::waiterTableFull('receivers', $this->waiterCapacity);
        }

        // On a rendezvous channel the registration IS the state change a sender is waiting
        // for - there is no room to free and no record to publish - so it has to be announced
        // like any other, after the lock is gone and never while holding it
        if ($this->isRendezvous()) {
            $this->wakeSenders();
        }

        return $entry;
    }

    /**
     * Withdraws a registerReceiver() registration
     *
     * Always succeeds and never hands anything back, because a registration never owned a
     * value: a record deposited against it is in the ring, where the next receiver takes it
     * and the sender goes on waiting until one does. That is what lets a select loser or a
     * cancelled context unwind without having to deliver a value it can no longer deliver.
     */
    public function cancelReceiver(int $token): void
    {
        $this->unpark($this->receivers, $token, self::WORD_RECEIVERS_PARKED);
    }

    /**
     * Announces a sender that is parked somewhere other than inside send()
     *
     * The mirror of registerReceiver(), and the same one-critical-section rule: what "ready"
     * means depends on what the sender is waiting for.
     *
     * @param int|null $ticket Ticket of a record this sender already deposited and is waiting
     *                         to see taken (the second half of a rendezvous send); null when
     *                         it is waiting for somewhere to put a value in the first place
     *
     * @return int|null Token for cancelSender(), or null when the sender can already proceed
     *                  and nothing was registered
     *
     * @throws IpcException When every entry of the senders table is taken
     */
    public function registerSender(?int $ticket = null): ?int
    {
        $wakeSlot = $this->wake->slot();
        $owner    = (int) getmypid();
        $limit    = max($this->capacity, 1);

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $head  = $this->word(self::WORD_HEAD);
        $ready = $this->word(self::WORD_CLOSED) !== 0;
        if (!$ready) {
            $ready = $ticket !== null
                ? $head > $ticket
                : $this->word(self::WORD_TAIL) - $head < $limit
                    && (!$this->isRendezvous() || $this->word(self::WORD_RECEIVERS_PARKED) > 0);
        }
        $entry = null;
        if (!$ready) {
            $entry = $this->senders->register($wakeSlot, $owner);
            if ($entry !== null) {
                $this->setWord(self::WORD_SENDERS_PARKED, $this->word(self::WORD_SENDERS_PARKED) + 1);
            }
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($ready) {
            return null;
        }
        if ($entry === null) {
            throw IpcException::waiterTableFull('senders', $this->waiterCapacity);
        }

        return $entry;
    }

    /**
     * Withdraws a registerSender() registration
     */
    public function cancelSender(int $token): void
    {
        $this->unpark($this->senders, $token, self::WORD_SENDERS_PARKED);
    }

    /**
     * Releases registrations whose owning process is gone, and reports how many
     *
     * A waiter parked inside recv() or send() takes its entry back on the way out, so it can
     * never go stale; a registration made from a consumer's event loop can, and on a
     * rendezvous channel a stale one would keep telling senders that a partner is present. A
     * deposit reaps before it reads the gate, so this is normally invisible - it is public for
     * a supervisor that wants to reclaim a dead worker's entries on its own schedule, and for
     * the tests that prove the reaping happens at all.
     *
     * Cheap it is not: the survey asks the operating system whether each owner still exists.
     * It runs entirely OUTSIDE the lock, and only what it found is re-verified and released
     * inside one.
     */
    public function reapDeadWaiters(): int
    {
        $deadReceivers = $this->surveyDead($this->receivers);
        $deadSenders   = $this->surveyDead($this->senders);

        if ($deadReceivers === [] && $deadSenders === []) {
            return 0;
        }

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $reaped = $this->releaseSurveyed($this->receivers, $deadReceivers, self::WORD_RECEIVERS_PARKED)
            + $this->releaseSurveyed($this->senders, $deadSenders, self::WORD_SENDERS_PARKED);

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        return $reaped;
    }

    /**
     * Writes one record into the ring if it fits, and returns the ticket it was written at
     *
     * @param bool $requireParkedReceiver Rendezvous handoff only: refuse to deposit while no
     *                                    receiver is waiting to take the value
     *
     * @return int|null Monotonic ticket of the deposited record, or null when there was no room
     */
    private function offer(ValueTag $tag, int $payload, bool $requireParkedReceiver): ?int
    {
        $limit = max($this->capacity, 1);

        // The gate below trusts the parked count, and a registration can outlive the process
        // that made it - so on a handoff the entries whose owner is gone are surveyed here,
        // outside the lock where the syscalls belong, and released inside the very critical
        // section that then reads the count. Without that a dead worker's leftover entry would
        // make every later send believe a partner is present
        $dead = $requireParkedReceiver && $this->word(self::WORD_RECEIVERS_PARKED) > 0
            ? $this->surveyDead($this->receivers)
            : [];

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $this->releaseSurveyed($this->receivers, $dead, self::WORD_RECEIVERS_PARKED);

        $closed   = $this->word(self::WORD_CLOSED);
        $head     = $this->word(self::WORD_HEAD);
        $tail     = $this->word(self::WORD_TAIL);
        $parked   = $this->word(self::WORD_RECEIVERS_PARKED);
        $accepted = $closed === 0
            && $tail - $head < $limit
            && (!$requireParkedReceiver || $parked > 0);
        if ($accepted) {
            $slot = $this->slotAddress($tail);
            $this->arena->writeWord($slot, $tag->value);
            $this->arena->writeWord($slot + 8, $payload);
            $this->setWord(self::WORD_TAIL, $tail + 1);
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($closed !== 0) {
            throw ClosedChannelException::onSend($this->address);
        }

        return $accepted ? $tail : null;
    }

    /**
     * Parks until the ring has room again
     *
     * @return bool Whether it is worth retrying (false = the deadline passed)
     */
    private function parkForRoom(?float $deadline): bool
    {
        $limit    = max($this->capacity, 1);
        $wakeSlot = $this->wake->slot();

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $hasRoom = $this->word(self::WORD_TAIL) - $this->word(self::WORD_HEAD) < $limit;
        $entry   = null;
        if (!$hasRoom) {
            // Registered and re-checked in ONE critical section: a receiver that frees a slot
            // after this point necessarily sees this entry, so the wakeup cannot be lost
            $entry = $this->senders->register($wakeSlot);
            if ($entry !== null) {
                // Counted only when an entry was actually taken: unpark() has nothing to give
                // back for a full table, so counting a failed registration would leave the
                // parked count permanently too high - and on a rendezvous channel that count
                // is the gate a handoff passes
                $this->setWord(self::WORD_SENDERS_PARKED, $this->word(self::WORD_SENDERS_PARKED) + 1);
            }
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($hasRoom) {
            return true;
        }

        $continue = $this->sleep($deadline);
        $this->unpark($this->senders, $entry, self::WORD_SENDERS_PARKED);

        return $continue;
    }

    /**
     * Parks until a record shows up (or the channel is closed, which also ends the wait)
     */
    private function parkForRecord(?float $deadline): bool
    {
        $wakeSlot = $this->wake->slot();

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $ready = $this->word(self::WORD_TAIL) > $this->word(self::WORD_HEAD)
            || $this->word(self::WORD_CLOSED) !== 0;
        $entry = null;
        if (!$ready) {
            $entry = $this->receivers->register($wakeSlot);
            if ($entry !== null) {
                // See parkForRoom(): a registration that found no free entry must not be
                // counted, or the count never comes back down
                $this->setWord(self::WORD_RECEIVERS_PARKED, $this->word(self::WORD_RECEIVERS_PARKED) + 1);
            }
        }

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($ready) {
            return true;
        }

        $continue = $this->sleep($deadline);
        $this->unpark($this->receivers, $entry, self::WORD_RECEIVERS_PARKED);

        return $continue;
    }

    /**
     * Rendezvous half of send(): waits until a receiver has actually taken ticket $ticket
     */
    private function awaitTaken(int $ticket, ?float $deadline): bool
    {
        while ($this->word(self::WORD_HEAD) <= $ticket) {
            if ($this->isClosed()) {
                throw ClosedChannelException::whileParked($this->address);
            }
            if (!$this->parkForRoom($deadline)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Entries of $table whose owning process is gone, read without the lock
     *
     * Makes one liveness syscall per occupied entry, which is why no caller runs it inside a
     * critical section. The raw word travels with the entry index so the release side can tell
     * "still the registration I surveyed" from "released and re-taken since".
     *
     * @return list<array{entry: int, word: int}>
     */
    private function surveyDead(WaiterTable $table): array
    {
        $dead = [];
        foreach ($table->entries() as $occupant) {
            if (!$this->wake->isOwnerAlive($occupant['slot'], $occupant['pid'])) {
                $dead[] = ['entry' => $occupant['entry'], 'word' => $occupant['word']];
            }
        }

        return $dead;
    }

    /**
     * Releases what surveyDead() found; the caller holds this channel's lock
     *
     * @param list<array{entry: int, word: int}> $dead
     *
     * @return int Entries actually released
     */
    private function releaseSurveyed(WaiterTable $table, array $dead, int $counterWord): int
    {
        $released = 0;
        foreach ($dead as $stale) {
            if ($table->wordAt($stale['entry']) !== $stale['word']) {
                // Released and re-taken between the survey and the lock: the entry now belongs
                // to somebody alive, and reclaiming it would unregister a live waiter
                continue;
            }
            $table->release($stale['entry']);
            $released++;
        }

        if ($released > 0) {
            $this->setWord($counterWord, max($this->word($counterWord) - $released, 0));
        }

        return $released;
    }

    /**
     * Deregisters a waiter entry, under the lock, and drops the parked counter with it
     */
    private function unpark(WaiterTable $table, ?int $entry, int $counterWord): void
    {
        if ($entry === null) {
            return;
        }

        $recovered = $this->arena->lockMutexAt($this->mutex);

        $table->release($entry);
        $this->setWord($counterWord, max($this->word($counterWord) - 1, 0));

        $this->arena->unlockMutexAt($this->mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Waits on the notification socket for one slice, honouring the deadline
     *
     * @return bool Whether there is still time left to retry
     */
    private function sleep(?float $deadline): bool
    {
        if ($deadline === null) {
            $this->wake->wait(self::WAIT_SLICE);

            return true;
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        $this->wake->wait(min($remaining, self::WAIT_SLICE));

        return true;
    }

    private function wakeReceivers(ValueTag $tag, int $payload): void
    {
        $this->wake->notifyAll(
            $this->receivers->occupants(),
            WakeEvent::forValue(WakeOpcode::Wake, $this->channelId(), $tag, $payload),
        );
    }

    private function wakeSenders(): void
    {
        $this->wake->notifyAll(
            $this->senders->occupants(),
            new WakeEvent(WakeOpcode::Wake, $this->channelId()),
        );
    }

    /**
     * Short, stable id of this channel for event records (the full address does not fit uint32)
     */
    private function channelId(): int
    {
        return ($this->address >> 4) & 0xFFFFFFFF;
    }

    private function slotAddress(int $counter): int
    {
        return $this->ringAddress + ($counter % max($this->capacity, 1)) * ValueRecord::SIZE;
    }

    private function word(int $index): int
    {
        return $this->arena->readWord($this->address + $index * 8);
    }

    private function setWord(int $index, int $value): void
    {
        $this->arena->writeWord($this->address + $index * 8, $value);
    }
}
