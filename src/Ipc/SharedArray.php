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
 * A fixed-capacity vector of value records, mutable by every process of the family
 *
 * This is the container a plain PHP array cannot be. A `zend_array` grows by reallocating
 * its bucket block through the engine's allocator, which for arena memory means the block
 * silently moves into the private heap of whichever worker triggered the growth - and the
 * spike showed the engine writes that private pointer into the SHARED struct before it
 * aborts, so siblings go on reading plausible garbage with no signal at all (EPIC #15,
 * correction #6). A container that never grows has no such moment: capacity is decided when
 * it is created, an index outside it is a typed error, and every slot is one 16-byte record.
 *
 * ```text
 *   header (2 words)   capacity | reserved
 *   slots              capacity records of 16 bytes, all NIL to begin with
 * ```
 *
 * ## Locking: a stripe from the bank, taken on reads as well as writes
 *
 * The instance lock is `Arena::stripeFor($address)` - unrelated arrays usually land on
 * different stripes, and two that collide merely serialize. Both halves of an element access
 * take it, because reading a tag and a payload together is exactly the two-word read that was
 * measured to tear (~1.3 % of unlocked reads saw two generations - correction #1). Only a
 * single aligned word may be read without the lock (correction #2), which is what count()
 * and the capacity read below do; skipping the lock on element reads would need a proof that
 * the tag cannot change under the reader, and v1 does not make that promise.
 *
 * @implements \ArrayAccess<int, mixed>
 * @implements \IteratorAggregate<int, mixed>
 */
final class SharedArray implements \ArrayAccess, \Countable, \IteratorAggregate
{
    private const int WORD_CAPACITY = 0;
    private const int HEADER_WORDS  = 2;

    private readonly Arena $arena;

    private readonly int $capacity;

    private readonly int $stripe;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly ArenaAllocator $allocator,
        private readonly ValueCodec $codec,
        private readonly int $address,
    ) {
        $this->arena = $allocator->arena();
        if (!$this->arena->contains($address, self::HEADER_WORDS * 8)) {
            throw IpcException::notShared('shared array', $address);
        }
        $this->capacity = $this->arena->readWord($address + self::WORD_CAPACITY * 8);
        $this->stripe   = $this->arena->stripeFor($address);
    }

    /**
     * Creates a shared array of exactly $capacity slots, every one of them null
     *
     * @param string|null $name Roots-directory name siblings can find the array by
     */
    public static function create(
        ArenaAllocator $allocator,
        ValueCodec $codec,
        int $capacity,
        ?string $name = null,
    ): self {
        if ($capacity <= 0) {
            throw IpcException::invalidCapacity('Shared array', $capacity);
        }
        $arena   = $allocator->arena();
        $address = $arena->allocate(self::HEADER_WORDS * 8 + $capacity * ValueRecord::SIZE, 16);

        // Kernel-zeroed memory already reads as capacity slots of tag NIL
        $arena->writeWord($address + self::WORD_CAPACITY * 8, $capacity);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($allocator, $codec, $address);
    }

    /**
     * Binds a shared array another process created, by address
     */
    public static function attach(ArenaAllocator $allocator, ValueCodec $codec, int $address): self
    {
        return new self($allocator, $codec, $address);
    }

    /**
     * Binds a shared array published in the arena roots directory
     */
    public static function open(ArenaAllocator $allocator, ValueCodec $codec, string $name): self
    {
        return new self($allocator, $codec, $allocator->arena()->requireRoot($name));
    }

    /**
     * Address of the header - what a value record referencing this array carries
     */
    public function address(): int
    {
        return $this->address;
    }

    /**
     * Fixed number of slots; this never changes for the lifetime of the array
     */
    #[\Override]
    public function count(): int
    {
        return $this->capacity;
    }

    /**
     * Whether a lock of this array was ever recovered from a worker that died holding it
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * @param int $offset
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return \is_int($offset) && $offset >= 0 && $offset < $this->capacity;
    }

    /**
     * @param int $offset
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        $slot = $this->slotAddress($offset);

        $recovered = $this->arena->lockStripe($this->stripe);

        $tag     = $this->arena->readWord($slot);
        $payload = $this->arena->readWord($slot + 8);

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        // Materializing a value is an engine call that allocates: never under the lock
        return $this->codec->decode(ValueTag::from($tag), $payload);
    }

    /**
     * @param int|null $offset
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            // Appending would mean growing, and growth is the one thing this container cannot do
            throw IpcException::outOfRange($this->capacity, $this->capacity);
        }
        $slot = $this->slotAddress($offset);
        // Encoded before the lock: interning a string allocates arena memory, and a value
        // that cannot be shared has to throw without ever taking a lock
        [$tag, $payload] = $this->codec->encode($value);

        $recovered = $this->arena->lockStripe($this->stripe);

        $this->arena->writeWord($slot, $tag->value);
        $this->arena->writeWord($slot + 8, $payload);

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Clears a slot back to null; the slot itself stays, because capacity is fixed
     *
     * @param int $offset
     */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->offsetSet($offset, null);
    }

    /**
     * @return \Traversable<int, mixed>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        for ($index = 0; $index < $this->capacity; $index++) {
            yield $index => $this->offsetGet($index);
        }
    }

    /**
     * Every slot as an ordinary PHP array - a REQUEST-local copy of the current values
     *
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator());
    }

    private function slotAddress(mixed $offset): int
    {
        if (!\is_int($offset) || $offset < 0 || $offset >= $this->capacity) {
            throw IpcException::outOfRange(\is_int($offset) ? $offset : -1, $this->capacity);
        }

        return $this->address + self::HEADER_WORDS * 8 + $offset * ValueRecord::SIZE;
    }
}
