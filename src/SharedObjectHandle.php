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

namespace Lisachenko\SharedData;

use FFI\CData;
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\StringEntry;

/**
 * The SYNCHRONIZED way to read and write the properties of a shared mutable object
 *
 * A shared object is an ordinary PHP instance, so `$object->counter = 1` compiles, runs and
 * even works: the engine writes the value straight into the arena and every sibling sees it
 * immediately (docs/shared-memory-model.md, §2). What it is not is *synchronized*, and for
 * anything but a scalar it is not even safe:
 *
 *  - a plain write is two stores (payload word, then type word), and a reader without the
 *    lock was measured to observe the two halves from different writes in ~1.3 % of reads;
 *  - `$object->name = 'x'` stores a REQUEST-HEAP `zend_string` pointer inside shared memory.
 *    The writing process reads it back perfectly; a sibling following it dereferences memory
 *    that belongs to another process. The same is true of arrays and of objects that are not
 *    themselves arena-resident.
 *
 * This handle is the path that does it correctly: every write interns or validates its
 * payload BEFORE taking the lock, and the critical section is nothing but aligned word
 * stores, payload first and type word second. Reads take the same stripe lock, because the
 * *type* of a slot can change under them - that is exactly the case correction #1 says needs
 * the lock. The lock is `Arena::stripeFor($address)`, so unrelated objects rarely contend and
 * two that collide merely serialize.
 *
 * Deliberately NOT done here: engine `write_property` handlers. A persistent clone is rewired
 * to `std_object_handlers` by construction (that is the only handlers block whose address
 * survives a request, let alone a fork), so there is no hook to install without giving that
 * up. Direct property writes therefore stay legal and unsynchronized, and this API is the
 * synchronized alternative rather than an enforcement layer.
 *
 * ## What may be written
 *
 * | Slot | Rule |
 * |---|---|
 * | scalar (`null`/`bool`/`int`/`float`) | overwritten in place, payload word then type word |
 * | string | new bytes are interned in the ARENA, then the 8-byte pointer is swapped; the previous block leaks until the arena dies, because a reader may still be following it |
 * | object reference | pointer swap to another object of THIS arena only |
 * | array | refused - a shared `zend_array` cannot grow, so it stays sealed immutable (`Ipc\SharedArray` is the mutable collection) |
 *
 * Declared property types are enforced here, because the write goes straight into the slot
 * and the engine never gets to check them.
 */
final class SharedObjectHandle
{
    /**
     * Property name => slot index in properties_table, from the class entry of THIS process
     *
     * @var array<string, int>
     */
    private array $slots;

    /**
     * Cached word views per property slot, so a critical section never creates a CData
     *
     * @var array<string, array{words: CData, types: CData, doubles: CData}>
     */
    private array $views = [];

    private readonly Arena $arena;

    private readonly int $stripe;

    /**
     * zval* at properties_table[0] - the base every slot view is derived from
     */
    private readonly CData $tableBase;

    private bool $recoveredLock = false;

    /**
     * @param PersistentStore $store      Owner of the registry this object belongs to
     * @param ArenaAllocator  $allocator  Where new string payloads are interned
     * @param CData           $object     zend_object* of the shared clone
     * @param CData           $classEntry zend_class_entry* bound by THIS process (never the
     *                                    advisory pointer inside the shared struct)
     * @param int             $address    Arena address of the clone - its cross-process identity
     * @param string          $className  For error messages and property reflection
     *
     * @internal Created by PersistentStore::mutableHandle()
     */
    public function __construct(
        private readonly PersistentStore $store,
        private readonly ArenaAllocator $allocator,
        private readonly CData $object,
        private readonly CData $classEntry,
        private readonly int $address,
        private readonly string $className,
    ) {
        $this->arena     = $allocator->arena();
        $this->stripe    = $this->arena->stripeFor($address);
        $this->tableBase = Core::cast('zval *', Core::addr($object->properties_table[0]));
        $this->slots     = array_flip(Persister::propertySlots($classEntry));
    }

    /**
     * Arena address of this object: the identity to hand to another process
     */
    public function address(): int
    {
        return $this->address;
    }

    /**
     * The stripe mutex serializing every access to this object
     */
    public function stripe(): int
    {
        return $this->stripe;
    }

    /**
     * The PHP instance, for ordinary (unsynchronized) reads
     */
    public function instance(): object
    {
        return $this->store->attachObject($this->address);
    }

    /**
     * Whether any lock taken by this handle was recovered from a worker that died holding it
     *
     * A recovered lock means the previous owner was killed between the two stores of a write,
     * so a reader may have seen a payload word that does not match its type word. Every write
     * through this handle repairs that by construction (it rewrites both words), which is why
     * recovery is reported rather than thrown - the caller decides whether the value it just
     * read has to be treated as suspect.
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * Reads a scalar property under the stripe lock
     */
    public function readScalar(string $property): int|float|bool|null
    {
        [$type, $lval, $dval] = $this->readSlot($property);

        return match ($type) {
            ReflectionValue::IS_UNDEF, ReflectionValue::IS_NULL => null,
            ReflectionValue::IS_TRUE   => true,
            ReflectionValue::IS_FALSE  => false,
            ReflectionValue::IS_LONG   => $lval,
            ReflectionValue::IS_DOUBLE => $dval,
            default => throw SharedMutationException::unexpectedSlotType(
                $this->className,
                $property,
                'scalar',
                $type,
            ),
        };
    }

    /**
     * Reads a string property under the stripe lock
     *
     * The bytes themselves are never copied while the lock is held: the critical section
     * takes the pointer, and the string is materialized afterwards. That is safe precisely
     * because arena memory is never reclaimed per block - the previous payload of a slot
     * stays readable even after another process has swapped it away.
     */
    public function readString(string $property): ?string
    {
        [$type, $lval] = $this->readSlot($property);

        if ($type === ReflectionValue::IS_NULL || $type === ReflectionValue::IS_UNDEF) {
            return null;
        }
        if ($type !== ReflectionValue::IS_STRING) {
            throw SharedMutationException::unexpectedSlotType($this->className, $property, 'string', $type);
        }

        return StringEntry::fromCData(Core::pointerAtAddress('zend_string *', $lval))->getStringValue();
    }

    /**
     * Reads an object-reference property and attaches the target for this request
     */
    public function readReference(string $property): ?object
    {
        [$type, $lval] = $this->readSlot($property);

        if ($type === ReflectionValue::IS_NULL || $type === ReflectionValue::IS_UNDEF) {
            return null;
        }
        if ($type !== ReflectionValue::IS_OBJECT) {
            throw SharedMutationException::unexpectedSlotType($this->className, $property, 'object', $type);
        }

        // Attaching runs engine code and may register an object: never under a lock
        return $this->store->attachObject($lval);
    }

    /**
     * Reads whatever the slot currently holds, dispatching on its type
     *
     * Array slots are the one shape read WITHOUT the lock, and legitimately so: a sealed
     * array can neither be grown nor replaced through this API, so the slot is immutable and
     * a lock would guard a value that cannot change.
     */
    public function read(string $property): mixed
    {
        [$type, $lval, $dval] = $this->readSlot($property);

        return match ($type) {
            ReflectionValue::IS_UNDEF, ReflectionValue::IS_NULL => null,
            ReflectionValue::IS_TRUE   => true,
            ReflectionValue::IS_FALSE  => false,
            ReflectionValue::IS_LONG   => $lval,
            ReflectionValue::IS_DOUBLE => $dval,
            ReflectionValue::IS_STRING => StringEntry::fromCData(
                Core::pointerAtAddress('zend_string *', $lval),
            )->getStringValue(),
            ReflectionValue::IS_OBJECT => $this->store->attachObject($lval),
            ReflectionValue::IS_ARRAY  => $this->readArray($property),
            default => throw SharedMutationException::unexpectedSlotType(
                $this->className,
                $property,
                'readable value',
                $type,
            ),
        };
    }

    /**
     * Overwrites a scalar property in place, payload word first and type word second
     */
    public function writeScalar(string $property, int|float|bool|null $value): void
    {
        $value = $this->assertAssignable($property, $value);

        [$typeInfo, $payload, $isDouble] = match (true) {
            $value === null  => [ReflectionValue::IS_NULL, 0, false],
            $value === true  => [ReflectionValue::IS_TRUE, 0, false],
            $value === false => [ReflectionValue::IS_FALSE, 0, false],
            \is_int($value)  => [ReflectionValue::IS_LONG, $value, false],
            default          => [ReflectionValue::IS_DOUBLE, $value, true],
        };

        $this->storeSlot($property, $typeInfo, $payload, $isDouble);
    }

    /**
     * Writes several scalar properties in ONE critical section
     *
     * The multi-slot half of the contract: a reader that takes the same lock either sees all
     * of these values or none of them. Writing them one by one would publish a half-applied
     * update between the calls - which is exactly what the sweep observed at the PHP level in
     * 2.7-3.8 % of unlocked reads of a three-property update.
     *
     * @param array<string, int|float|bool|null> $values Property name => value
     */
    public function writeScalars(array $values): void
    {
        $writes = [];
        foreach ($values as $property => $value) {
            $value = $this->assertAssignable($property, $value);
            $view  = $this->viewOf($property);

            $writes[] = match (true) {
                $value === null  => [$view, ReflectionValue::IS_NULL, 0, false],
                $value === true  => [$view, ReflectionValue::IS_TRUE, 0, false],
                $value === false => [$view, ReflectionValue::IS_FALSE, 0, false],
                \is_int($value)  => [$view, ReflectionValue::IS_LONG, $value, false],
                default          => [$view, ReflectionValue::IS_DOUBLE, $value, true],
            };
        }

        $recovered = $this->arena->lockStripe($this->stripe);

        foreach ($writes as [$view, $typeInfo, $payload, $isDouble]) {
            if ($isDouble) {
                $view['doubles'][0] = $payload;
            } else {
                $view['words'][0] = $payload;
            }
            $view['types'][2] = $typeInfo;
        }

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * Reads several scalar properties in ONE critical section
     *
     * The counterpart of writeScalars(): the values come from a single generation of the
     * object, which is the only way a caller can compare two slots and conclude anything.
     *
     * @param list<string> $properties
     *
     * @return array<string, int|float|bool|null>
     */
    public function readScalars(array $properties): array
    {
        $views = [];
        foreach ($properties as $property) {
            $views[$property] = $this->viewOf($property);
        }

        $raw = [];

        $recovered = $this->arena->lockStripe($this->stripe);

        foreach ($views as $property => $view) {
            $raw[$property] = [(int) $view['types'][2] & 0xFF, (int) $view['words'][0], (float) $view['doubles'][0]];
        }

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        $values = [];
        foreach ($raw as $property => [$type, $lval, $dval]) {
            $values[$property] = match ($type) {
                ReflectionValue::IS_UNDEF, ReflectionValue::IS_NULL => null,
                ReflectionValue::IS_TRUE   => true,
                ReflectionValue::IS_FALSE  => false,
                ReflectionValue::IS_LONG   => $lval,
                ReflectionValue::IS_DOUBLE => $dval,
                default => throw SharedMutationException::unexpectedSlotType(
                    $this->className,
                    $property,
                    'scalar',
                    $type,
                ),
            };
        }

        return $values;
    }

    /**
     * Interns new bytes in the arena and swaps the 8-byte string pointer under the lock
     *
     * The old block is NOT freed: the arena is bump-allocated and a sibling may be holding
     * the previous pointer at this very moment (an aligned 8-byte read never tears, so it is
     * following a complete, valid string - just the older one). Rewriting a string property N
     * times therefore costs N blocks until the arena dies; see docs/shared-memory-model.md §6.
     */
    public function writeString(string $property, ?string $value): void
    {
        $this->assertAssignable($property, $value);

        if ($value === null) {
            $this->writeScalar($property, null);

            return;
        }

        // Interning allocates arena memory and calls into the engine: strictly before the lock
        $interned = StringEntry::persistentInterned($value, $this->allocator);
        $pointer  = Core::addressOf($interned->getRawValue());

        // Bare IS_STRING: an interned, immutable payload is held without refcounting, which
        // is what lets every process copy the value around without touching a shared header
        $this->storeSlot($property, ReflectionValue::IS_STRING, $pointer, false);
    }

    /**
     * Points an object property at another object of THIS arena
     *
     * The refcounted IS_OBJECT_EX type stays: request code copies such a value around
     * normally, and the target's refcount pin absorbs every addref and delref it will see.
     * Overwriting a slot that pointed at another shared object simply drops one unit of that
     * object's pin, which is why no destructor call is needed - and must not be attempted
     * under a lock anyway.
     */
    public function writeReference(string $property, ?object $target): void
    {
        $this->assertAssignable($property, $target);

        if ($target === null) {
            $this->writeScalar($property, null);

            return;
        }

        $targetAddress = $this->store->addressOfInstance($target);
        if ($targetAddress === null) {
            throw SharedMutationException::targetNotShared($this->className, $property);
        }

        $refcounted = 1 << Core::engineConstant('Z_TYPE_FLAGS_SHIFT');

        $this->storeSlot($property, ReflectionValue::IS_OBJECT | $refcounted, $targetAddress, false);
    }

    /**
     * The one write path: payload word, then type word, under the object's stripe lock
     *
     * The slot's current type is examined INSIDE the same critical section, so a sealed array
     * cannot slip in between a check and a write - and the refusal is thrown only after the
     * lock is released, because throwing from a critical section would leave the stripe held
     * (and, in an FFI callback, would not even be catchable).
     */
    private function storeSlot(string $property, int $typeInfo, int|float $payload, bool $isDouble): void
    {
        $view = $this->viewOf($property);

        $recovered = $this->arena->lockStripe($this->stripe);

        // Payload BEFORE type, so a reader that legitimately skips the lock (a single aligned
        // 8-byte pointer read of a slot whose type is fixed) can never see a new pointer under
        // an old type or the other way round
        $sealed = ((int) $view['types'][2] & 0xFF) === ReflectionValue::IS_ARRAY;
        if (!$sealed) {
            if ($isDouble) {
                $view['doubles'][0] = $payload;
            } else {
                $view['words'][0] = $payload;
            }
            $view['types'][2] = $typeInfo;
        }

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if ($sealed) {
            throw SharedMutationException::sealedArrayProperty($this->className, $property);
        }
    }

    /**
     * Reads the three words of a slot under one lock
     *
     * All three come from the same critical section on purpose: the payload is interpreted
     * differently depending on the type word, so reading them apart is exactly the torn read
     * the sweep measured. Materializing the value (a string, an attached object) happens
     * after the lock is released - it allocates, and allocation under an arena lock is
     * forbidden.
     *
     * @return array{0: int, 1: int, 2: float} zval type, payload as an integer, payload as a double
     */
    private function readSlot(string $property): array
    {
        $view = $this->viewOf($property);

        $recovered = $this->arena->lockStripe($this->stripe);

        $typeInfo = (int) $view['types'][2];
        $lval     = (int) $view['words'][0];
        $dval     = (float) $view['doubles'][0];

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        return [$typeInfo & 0xFF, $lval, $dval];
    }

    /**
     * Binds (once per process and property) the word views used inside critical sections
     *
     * @return array{words: CData, types: CData, doubles: CData}
     */
    private function viewOf(string $property): array
    {
        if (isset($this->views[$property])) {
            return $this->views[$property];
        }
        if (!isset($this->slots[$property])) {
            throw SharedMutationException::unknownProperty($this->className, $property);
        }
        $slot = Core::addr($this->tableBase[$this->slots[$property]]);

        return $this->views[$property] = [
            // value word at offset 0, type_info at offset 8 (word index 2 of a uint32 view).
            // u2 is deliberately never touched: for an uninitialized typed property it carries
            // the engine's property flags
            'words'   => Core::cast('uint64_t *', $slot),
            'types'   => Core::cast('uint32_t *', $slot),
            'doubles' => Core::cast('double *', $slot),
        ];
    }

    /**
     * Reads a sealed array slot through ordinary engine access
     *
     * Immutable payloads are copied on write by the engine, so what the caller receives is a
     * request-local array that shares its buckets with the arena until it is modified.
     */
    private function readArray(string $property): array
    {
        $instance = $this->instance();

        try {
            $value = new \ReflectionProperty($this->className, $property)->getValue($instance);
        } catch (\ReflectionException) {
            $value = $instance->{$property};
        }
        \assert(\is_array($value));

        return $value;
    }

    /**
     * Enforces the property's declared type, which the engine never gets to check
     *
     * @return int|float|bool|null The value to store, widened to float where the declaration
     *                             asks for it (the one coercion the engine would have done)
     */
    private function assertAssignable(string $property, mixed $value): mixed
    {
        $declared = $this->declaredTypeOf($property);
        if ($declared === null) {
            return $value;
        }
        $names = $declared['names'];
        if (\in_array('mixed', $names, true)) {
            return $value;
        }

        if ($value === null) {
            if (!$declared['nullable'] && !\in_array('null', $names, true)) {
                throw SharedMutationException::typeMismatch(
                    $this->className,
                    $property,
                    implode('|', $names),
                    'null',
                );
            }

            return null;
        }

        if (\is_object($value)) {
            foreach ($names as $name) {
                if ($value instanceof $name) {
                    return $value;
                }
            }

            throw SharedMutationException::typeMismatch(
                $this->className,
                $property,
                implode('|', $names),
                \get_class($value),
            );
        }

        $given = \get_debug_type($value);
        if (\in_array($given, $names, true)) {
            return $value;
        }
        // int -> float is the only widening a typed property performs silently
        if ($given === 'int' && \in_array('float', $names, true)) {
            return (float) $value;
        }

        throw SharedMutationException::typeMismatch($this->className, $property, implode('|', $names), $given);
    }

    /**
     * The declared type of one property, or null when it is untyped (or not reflectable)
     *
     * A property declared private by a PARENT class owns a slot in this object but is not
     * reachable through ReflectionProperty on the child; such a slot is written without a
     * type check, exactly as an untyped property is.
     *
     * @return array{names: list<string>, nullable: bool}|null
     */
    private function declaredTypeOf(string $property): ?array
    {
        try {
            $type = new \ReflectionProperty($this->className, $property)->getType();
        } catch (\ReflectionException) {
            return null;
        }
        if ($type === null) {
            return null;
        }

        $names = [];
        foreach ($type instanceof \ReflectionNamedType ? [$type] : $this->typeParts($type) as $part) {
            $names[] = $part->getName();
        }

        return ['names' => $names, 'nullable' => $type->allowsNull()];
    }

    /**
     * @return list<\ReflectionNamedType>
     */
    private function typeParts(\ReflectionType $type): array
    {
        $parts = [];
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if ($part instanceof \ReflectionNamedType) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }
}
