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

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use ZEngine\Core;
use ZEngine\Generated\zend_string;
use ZEngine\Type\StringEntry;

/**
 * Turns PHP values into 16-byte records and back, without ever encoding one
 *
 * This class is where the Never-Serialize Rule is actually enforced. There is no branch in
 * it that produces bytes describing a value: a scalar IS the payload word, a string becomes
 * an arena-resident zend_string and the payload is its address, an object and a shared array
 * contribute nothing but their address. Anything that has no address-shaped form - a plain
 * array, a resource, an object this worker family does not share - is refused with
 * NotShareableValueException rather than quietly encoded.
 *
 * A closure is the one value whose shareability is not decided by its type at all: it travels
 * only if it was REGISTERED before the fork barrier (ClosureProvenance), and the payload is
 * then the address of its arena record. Everything else about it - its class, its handlers,
 * its op_array - is deliberately never examined, because a stale post-fork address was
 * observed holding a valid Closure of a different function (EPIC #15, correction #8).
 *
 * ## Sending a string costs arena bytes
 *
 * Strings are interned into the arena on the way in (`StringEntry::persistentInterned()`
 * through the ArenaAllocator), which is a structural memcpy of the bytes into shared memory,
 * not a serialization: the receiver ends up with a real `zend_string` at a real address.
 * The arena is bump-allocated and never frees, so each distinct send consumes bytes for the
 * lifetime of the family - a workload that streams unbounded strings must size the arena for
 * it (see Arena's allocation model). Scalars, objects and shared arrays consume nothing.
 *
 * ## Receiving is zero-copy
 *
 * A string record materializes as a PHP string pointing straight at the arena block: the
 * zval is non-refcounted because the block is flagged immutable, exactly like an interned or
 * opcache-SHM string, so the engine copies the POINTER around and copy-on-writes into
 * request memory if userland mutates it. An object record materializes through the store,
 * which registers the shared zend_object in this request's object store; the address the
 * sender saw and the address the receiver sees are the same eight bytes.
 */
final class ValueCodec
{
    /**
     * @param ArenaAllocator        $allocator Source of arena memory for string records
     * @param PersistentStore|null  $store     Registry that decides which objects are shared;
     *                                         without one, object records cannot be built
     * @param ClosureProvenance|null $closures Register of closures compiled before the fork
     *                                         barrier; without one, every closure is refused
     */
    public function __construct(
        private readonly ArenaAllocator $allocator,
        private readonly ?PersistentStore $store = null,
        private readonly ?ClosureProvenance $closures = null,
    ) {
    }

    public function arena(): Arena
    {
        return $this->allocator->arena();
    }

    public function allocator(): ArenaAllocator
    {
        return $this->allocator;
    }

    public function store(): ?PersistentStore
    {
        return $this->store;
    }

    public function closures(): ?ClosureProvenance
    {
        return $this->closures;
    }

    /**
     * Converts a PHP value into the tag and payload of a record
     *
     * Always called OUTSIDE the lock of the structure the record is going into: interning a
     * string allocates arena memory (which takes the allocator mutex) and rejecting a value
     * throws - neither is allowed while a ring or slot lock is held.
     *
     * @return array{0: ValueTag, 1: int}
     */
    public function encode(mixed $value): array
    {
        return match (true) {
            $value === null   => [ValueTag::Nil, 0],
            $value === true   => [ValueTag::True, 0],
            $value === false  => [ValueTag::False, 0],
            \is_int($value)   => [ValueTag::Int, $value],
            \is_float($value) => [ValueTag::Float, self::floatBits($value)],
            \is_string($value) => [ValueTag::Str, $this->internString($value)],
            \is_array($value) => throw NotShareableValueException::plainArray(),
            \is_object($value) => $this->encodeObject($value),
            \is_resource($value) => throw NotShareableValueException::resource(),
            default => throw NotShareableValueException::unsupportedType(\gettype($value)),
        };
    }

    /**
     * Materializes the PHP value a record describes
     *
     * Called OUTSIDE the lock as well: attaching an object registers it in the object store
     * and materializing a string builds a zval, both of which are engine calls that allocate.
     */
    public function decode(ValueTag $tag, int $payload): mixed
    {
        return match ($tag) {
            ValueTag::Nil, ValueTag::Close => null,
            ValueTag::True    => true,
            ValueTag::False   => false,
            ValueTag::Int     => $payload,
            ValueTag::Float   => self::bitsToFloat($payload),
            ValueTag::Str     => $this->readString($payload),
            ValueTag::Obj     => $this->attachObject($payload),
            ValueTag::Arr     => SharedArray::attach($this->allocator, $this, $payload),
            ValueTag::Closure => $this->resolveClosure($payload),
        };
    }

    /**
     * Interns a string into the arena and returns the address of the zend_string
     */
    private function internString(string $value): int
    {
        $interned = StringEntry::persistentInterned($value, $this->allocator);

        return Core::addressOf($interned->getRawValue());
    }

    /**
     * Rebuilds a PHP string over the arena block at $address, without copying its bytes
     */
    private function readString(int $address): string
    {
        $this->assertShared($address);

        return StringEntry::fromCData(Core::pointerAtAddress(zend_string::class, $address))->getStringValue();
    }

    /**
     * @return array{0: ValueTag, 1: int}
     */
    private function encodeObject(object $value): array
    {
        if ($value instanceof SharedArray) {
            return [ValueTag::Arr, $value->address()];
        }
        if ($value instanceof \Closure) {
            // Provenance, never shape: a stale post-fork closure address can hold a valid
            // Closure of a DIFFERENT function (EPIC #15, correction #8), so inspection can
            // never establish that sharing this one is safe. The one provenance that IS
            // sound is registration before the fork barrier, and it is a table lookup -
            // nothing about the object is examined here either way
            $record = $this->closures?->addressOfClosure($value);
            if ($record === null) {
                throw NotShareableValueException::closure();
            }

            return [ValueTag::Closure, $record];
        }
        if ($this->store === null) {
            throw NotShareableValueException::withoutStore($value::class);
        }

        $address = $this->store->addressOfInstance($value);
        if ($address === null) {
            throw NotShareableValueException::foreignObject($value::class);
        }

        return [ValueTag::Obj, $address];
    }

    /**
     * Rebuilds the closure a record describes, in the process that received the record
     *
     * The payload is the address of the arena RECORD, not of the closure: the record is what
     * lives in shared memory and what proves the closure predates the fork. Resolving it is a
     * bounds-checked table lookup followed by an integrity check on the object the record
     * points at - see ClosureProvenance::resolve().
     */
    private function resolveClosure(int $address): \Closure
    {
        $this->assertShared($address);
        if ($this->closures === null) {
            throw NotShareableValueException::closure();
        }

        return $this->closures->resolve($address);
    }

    private function attachObject(int $address): object
    {
        $this->assertShared($address);
        if ($this->store === null) {
            throw NotShareableValueException::withoutStore('the referenced class');
        }

        return $this->store->attachObject($address);
    }

    /**
     * Refuses an address that does not point into the arena
     *
     * A record whose payload leads outside the shared mapping is either a bug or memory the
     * receiving process cannot follow; either way, dereferencing it is what the bounds check
     * exists to prevent (EPIC #15, correction #6).
     */
    private function assertShared(int $address): void
    {
        if (!$this->arena()->contains($address, 8)) {
            throw NotShareableValueException::foreignAddress($address);
        }
    }

    /**
     * IEEE-754 bit pattern of a double, as a signed word the arena can store
     */
    private static function floatBits(float $value): int
    {
        /** @var array{1: int} $bits */
        $bits = unpack('q', pack('d', $value));

        return $bits[1];
    }

    private static function bitsToFloat(int $bits): float
    {
        /** @var array{1: float} $value */
        $value = unpack('d', pack('q', $bits));

        return $value[1];
    }
}
