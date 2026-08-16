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
 * The notification plane: one socket pair per process, carrying event records and nothing else
 *
 * Shared memory can hold state but cannot wake anybody: FFI offers no futex, no condition
 * variable and no atomics, and a robust pthread mutex only serializes access. So blocking is
 * done the one way PHP can actually do it - a descriptor a process can select() on - while
 * every VALUE stays in the arena. A sender that makes a channel non-empty or completes a
 * result slot writes ONE 16-byte WakeEvent to each parked process's socket; the receiver
 * wakes, drains, and re-reads the shared state to find out what actually happened.
 *
 * ## Why the pairs must exist before the fork
 *
 * A file descriptor is per-process: the arena can carry an address that means the same thing
 * everywhere, but never a handle. The only way for process A to write into process B's queue
 * is to hold a descriptor of it, and the only way to get one without descriptor passing is to
 * INHERIT it. create() therefore mints every pair up front, before any worker exists, and the
 * whole registry travels into the children as ordinary forked state. The arena side of the
 * registry is just the claim table: which pid owns which slot, so a notifier can turn "the
 * waiter parked in this structure" into "the pair I write to".
 *
 * ## Claiming, and re-claiming after a fork
 *
 * slot() is idempotent per process. A forked child inherits its parent's claim as PHP state,
 * notices the pid changed and claims an entry of its own, draining whatever its inherited
 * read end still buffers (events addressed to the parent are not this process's business).
 * Entries whose owner has died are recycled, so a supervisor may respawn workers forever
 * without exhausting a table sized for the pool.
 *
 * ## The sockets are never a data path
 *
 * Every byte written here goes through writeRecord(), which accepts nothing but a 16-byte
 * WakeEvent. observeWrites() exposes that single choke point so a test can prove the claim
 * rather than assert it in prose.
 */
final class WakeRegistry
{
    public const string DEFAULT_ROOT = 'ipc.wake';

    /**
     * Processes a default registry can serve; two descriptors each, so it stays far below
     * the usual 1024 open-file limit
     */
    public const int DEFAULT_SLOTS = 32;

    private const int WORD_CAPACITY = 0;
    private const int WORD_MUTEX    = 1;
    private const int HEADER_WORDS  = 4;

    /**
     * Read ends, one per slot; only the slot this process claimed is ever read from
     *
     * @var array<int, resource>
     */
    private array $readers = [];

    /**
     * Write ends, one per slot; any process may write to any of them
     *
     * @var array<int, resource>
     */
    private array $writers = [];

    private ?int $slot = null;

    private int $ownerPid = 0;

    private bool $recoveredLock = false;

    /**
     * Bytes read from this process's socket that did not complete a record yet
     */
    private string $residue = '';

    /** @var (callable(int, string): void)|null */
    private $observer = null;

    private function __construct(
        private readonly Arena $arena,
        private readonly int $address,
        private readonly int $capacity,
    ) {
    }

    /**
     * Mints the socket pairs and the claim table; call this ONCE, before any worker is forked
     *
     * @param int         $slots Processes the registry can serve (pairs are created eagerly)
     * @param string|null $name  Roots-directory name to publish the claim table under
     */
    public static function create(
        Arena $arena,
        int $slots = self::DEFAULT_SLOTS,
        ?string $name = self::DEFAULT_ROOT,
    ): self {
        if ($slots <= 0) {
            throw IpcException::invalidCapacity('Wake registry', $slots);
        }

        $address = $arena->allocate((self::HEADER_WORDS + $slots) * 8, 64);
        $mutex   = $arena->allocateMutex();

        $arena->writeWord($address + self::WORD_CAPACITY * 8, $slots);
        $arena->writeWord($address + self::WORD_MUTEX * 8, $mutex);

        $registry = new self($arena, $address, $slots);
        for ($slot = 0; $slot < $slots; $slot++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
                throw IpcException::wakeRegistryNotInherited();
            }
            stream_set_blocking($pair[0], false);
            stream_set_blocking($pair[1], false);

            $registry->readers[$slot] = $pair[0];
            $registry->writers[$slot] = $pair[1];
        }

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return $registry;
    }

    /**
     * Address of the claim table (the arena half of the registry)
     */
    public function address(): int
    {
        return $this->address;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * This process's wake slot, claiming one on first use (and again after a fork)
     */
    public function slot(): int
    {
        $pid = getmypid();
        if ($this->slot !== null && $this->ownerPid === $pid) {
            return $this->slot;
        }

        $slot = $this->claim((int) $pid);

        $this->slot     = $slot;
        $this->ownerPid = (int) $pid;
        // Whatever the parent had queued belongs to the parent; this process starts level
        $this->residue = '';
        $this->drain();

        return $slot;
    }

    /**
     * The descriptor a scheduler selects on, so a consumer can integrate its own event loop
     *
     * Readiness means "something changed somewhere" and nothing more: drain it, then re-poll
     * the structures this process is waiting on. That is the whole contract - the socket is
     * level-triggered signalling, never a queue of values.
     *
     * @return resource
     */
    public function stream()
    {
        $slot = $this->slot();

        return $this->readers[$slot] ?? throw IpcException::wakeRegistryNotInherited();
    }

    /**
     * The pid that currently owns $slot, or 0 when the slot is free
     *
     * A single aligned word read and no lock: the claim table is one word per entry, and an
     * aligned 8-byte load never tears (EPIC #15, correction #2), so the answer is either the
     * old owner or the new one and never a mixture of the two.
     */
    public function ownerOf(int $slot): int
    {
        if ($slot < 0 || $slot >= $this->capacity) {
            return 0;
        }

        return (int) $this->arena->readWord($this->entryAddress($slot));
    }

    /**
     * Whether $slot is still held by $pid and $pid is still running
     *
     * This is the liveness test a structure holding LONG-LIVED registrations needs: a waiter
     * parked inside a blocking call always takes its entry back on the way out, but a
     * registration made from a consumer's own event loop survives the process that made it,
     * and on a rendezvous channel a surviving registration is the difference between "a
     * partner is present" and "nobody is there".
     *
     * Both halves of the check matter. The pid may be gone; or the pid may be gone AND its
     * slot already recycled to a new worker, which is a live process that never registered
     * anywhere - so the claim table has to agree that this pid still owns this slot.
     *
     * **Makes a syscall, so it is never called from inside a critical section.** Callers scan
     * lock-free, then re-verify what they found under the structure's lock before acting on it
     * (the same shape claim() uses for its own scan).
     */
    public function isOwnerAlive(int $slot, int $pid): bool
    {
        if ($pid <= 0) {
            // No owner was recorded: the entry belongs to a caller that releases it itself,
            // so there is nothing here that could ever go stale
            return true;
        }

        return $this->ownerOf($slot) === $pid && self::isAlive($pid);
    }

    /**
     * Sends one event to a parked process
     */
    public function notify(int $slot, WakeEvent $event): void
    {
        $writer = $this->writers[$slot] ?? null;
        if ($writer === null) {
            return;
        }
        $this->writeRecord($slot, $event, $writer);
    }

    /**
     * Sends one event to every process in $slots (a waiter table's occupants)
     *
     * @param list<int> $slots
     */
    public function notifyAll(array $slots, WakeEvent $event): void
    {
        foreach ($slots as $slot) {
            $this->notify($slot, $event);
        }
    }

    /**
     * Waits up to $seconds for events addressed to this process, then drains them
     *
     * @return list<WakeEvent>
     */
    public function wait(float $seconds): array
    {
        $stream  = $this->stream();
        $read    = [$stream];
        $write   = [];
        $except  = [];
        $seconds = max($seconds, 0.0);

        $ready = @stream_select($read, $write, $except, (int) $seconds, (int) (fmod($seconds, 1.0) * 1_000_000));
        if ($ready === false || $ready === 0) {
            return [];
        }

        return $this->drain();
    }

    /**
     * Reads every event queued for this process without blocking
     *
     * @return list<WakeEvent>
     */
    public function drain(): array
    {
        $stream = $this->readers[$this->slot ?? -1] ?? null;
        if ($stream === null) {
            return [];
        }

        while (($chunk = @fread($stream, WakeEvent::SIZE * 64)) !== false && $chunk !== '') {
            $this->residue .= $chunk;
        }

        $events = [];
        while (\strlen($this->residue) >= WakeEvent::SIZE) {
            $event         = WakeEvent::fromBytes(substr($this->residue, 0, WakeEvent::SIZE));
            $this->residue = substr($this->residue, WakeEvent::SIZE);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Installs an inspector over the ONE place that writes to a socket
     *
     * Every byte this package sends between processes passes through here, which is what
     * makes "the sockets never carry values" a testable statement instead of a promise.
     *
     * @param (callable(int, string): void)|null $observer Receives the target slot and the
     *                                                     exact bytes about to be written
     */
    public function observeWrites(?callable $observer): void
    {
        $this->observer = $observer;
    }

    /**
     * Whether this registry ever recovered its claim-table lock from a died owner
     *
     * The table is one word per entry, so a lock inherited through EOWNERDEAD guards state
     * that cannot be half-written; recovery is reported rather than swallowed because a
     * supervisor wants to know that a worker died holding a shared lock.
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * Gives this process's slot back to the table (optional; a dead owner is recycled anyway)
     */
    public function releaseSlot(): void
    {
        if ($this->slot === null || $this->ownerPid !== getmypid()) {
            return;
        }
        $mutex = $this->mutexAddress();

        $recovered = $this->arena->lockMutexAt($mutex);

        $this->arena->writeWord($this->entryAddress($this->slot), 0);

        $this->arena->unlockMutexAt($mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;
        $this->slot          = null;
    }

    /**
     * Takes a free (or dead-owner) entry for $pid
     */
    private function claim(int $pid): int
    {
        // Scanned WITHOUT the lock: single aligned word reads never tear, and posix_kill()
        // has no business inside a critical section. Everything found here is re-verified
        // under the lock before it is claimed
        $candidates = [];
        for ($slot = 0; $slot < $this->capacity; $slot++) {
            $owner = $this->arena->readWord($this->entryAddress($slot));
            if ($owner === 0 || !self::isAlive($owner)) {
                $candidates[] = [$slot, $owner];
            }
        }

        $mutex   = $this->mutexAddress();
        $claimed = null;

        $recovered = $this->arena->lockMutexAt($mutex);

        foreach ($candidates as [$slot, $owner]) {
            if ($this->arena->readWord($this->entryAddress($slot)) === $owner) {
                $this->arena->writeWord($this->entryAddress($slot), $pid);
                $claimed = $slot;

                break;
            }
        }

        $this->arena->unlockMutexAt($mutex);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($claimed === null) {
            throw IpcException::wakeRegistryFull($this->capacity);
        }
        if (!isset($this->writers[$claimed])) {
            // The registry was not inherited: this process never got the descriptors
            throw IpcException::wakeRegistryNotInherited();
        }

        return $claimed;
    }

    /**
     * The single choke point every cross-process byte of this package passes through
     *
     * @param resource $writer
     */
    private function writeRecord(int $slot, WakeEvent $event, $writer): void
    {
        $bytes = $event->toBytes();
        \assert(\strlen($bytes) === WakeEvent::SIZE);

        if ($this->observer !== null) {
            ($this->observer)($slot, $bytes);
        }

        // Non-blocking on purpose: a full queue means the target already has more wakeups
        // pending than it has processed, and one more would tell it nothing new
        @fwrite($writer, $bytes);
    }

    private function mutexAddress(): int
    {
        return $this->arena->readWord($this->address + self::WORD_MUTEX * 8);
    }

    private function entryAddress(int $slot): int
    {
        return $this->address + (self::HEADER_WORDS + $slot) * 8;
    }

    private static function isAlive(int $pid): bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return true;
        }

        return posix_kill($pid, 0);
    }
}
