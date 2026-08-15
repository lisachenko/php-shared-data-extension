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
 * One shared 64-bit cell, read and written by every process of the family
 *
 * "Atomic" here is a statement about the ENGINE, not about the CPU: FFI exposes no atomics,
 * no CAS and no fences, so the only true hardware guarantee available is that an ALIGNED
 * 8-byte load or store never tears - measured over two million unlocked reads while another
 * process wrote the same word (EPIC #15, correction #2). get() and set() ride exactly that
 * guarantee and take no lock at all.
 *
 * Read-modify-write is a different question: add() and compareAndSet() would need a real
 * CAS instruction, so they take a stripe mutex from the arena bank instead (chosen by the
 * cell's address, so unrelated counters rarely contend). That makes them correct and roughly
 * a lock's worth of cost - fine for wait-group counters and statistics, wrong for a hot inner
 * loop, which is what the honest name for v1 would be "mutex-backed atomics".
 */
final class AtomicInt
{
    private readonly int $stripe;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly Arena $arena,
        private readonly int $address,
    ) {
        $this->stripe = $arena->stripeFor($address);
    }

    /**
     * Allocates a cell in the arena, optionally publishing it under a name
     */
    public static function create(Arena $arena, int $initial = 0, ?string $name = null): self
    {
        $address = $arena->allocate(8, 8);
        $arena->writeWord($address, $initial);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($arena, $address);
    }

    /**
     * Binds a cell another process created, by address
     */
    public static function attach(Arena $arena, int $address): self
    {
        if (!$arena->contains($address, 8)) {
            throw IpcException::notShared('atomic cell', $address);
        }

        return new self($arena, $address);
    }

    /**
     * Binds a cell published in the arena roots directory
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
     * Plain aligned load - old value or new value, never a mixture
     */
    public function get(): int
    {
        return $this->arena->readWord($this->address);
    }

    /**
     * Plain aligned store
     */
    public function set(int $value): void
    {
        $this->arena->writeWord($this->address, $value);
    }

    /**
     * Adds $delta and returns the new value, serialized against every other process
     */
    public function add(int $delta): int
    {
        $recovered = $this->arena->lockStripe($this->stripe);

        $value = $this->arena->readWord($this->address) + $delta;
        $this->arena->writeWord($this->address, $value);

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        return $value;
    }

    /**
     * Sets the cell to $new if and only if it currently holds $expected
     */
    public function compareAndSet(int $expected, int $new): bool
    {
        $recovered = $this->arena->lockStripe($this->stripe);

        $matched = $this->arena->readWord($this->address) === $expected;
        if ($matched) {
            $this->arena->writeWord($this->address, $new);
        }

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        return $matched;
    }

    /**
     * Whether a stripe guarding this cell was ever recovered from a died owner
     *
     * The guarded state is a single word, so a lock inherited through EOWNERDEAD protects
     * something that cannot be half-written: recovery is reported, not repaired.
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }
}
