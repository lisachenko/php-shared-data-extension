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

/**
 * Every way a shared-mutable write can be refused, with the remedy in the message
 *
 * The mutation contract of a shared graph is narrow on purpose: a slot may hold a scalar, an
 * arena-interned string, or a pointer to another object of the same arena, and nothing else.
 * Anything wider would put a request-heap pointer into memory other processes read, which is
 * not an exception in the moment - it is a segfault in a sibling, later. So every refusal
 * below happens BEFORE any lock is taken and before any byte is written.
 */
final class SharedMutationException extends \RuntimeException
{
    /**
     * Mutable graphs need the fork-shared arena: there is nothing to synchronize without it
     */
    public static function requiresSharedMode(string $className): self
    {
        return new self(sprintf(
            'Cannot persist %s as mutable: mutable graphs exist only in a fork-shared arena. Boot the ' .
            'store with PersistentStore::bootShared($arena) - in the default (frozen) mode a persisted ' .
            'graph is rolled back to its snapshot at request end, so there is nothing to share.',
            $className,
        ));
    }

    /**
     * The object is not (or no longer) part of this store's shared registry
     */
    public static function notShared(string $className): self
    {
        return new self(sprintf(
            'The given %s instance is not a shared object of this store: only the instance returned by ' .
            'persist() (or attached through attachObject()) lives in the arena, and only such an ' .
            'instance has an address other processes can follow.',
            $className,
        ));
    }

    /**
     * A frozen graph refuses writes: its slots are restored from the snapshot at request end
     */
    public static function notMutable(string $className, int $address): self
    {
        return new self(sprintf(
            'The shared %s at 0x%x belongs to a FROZEN graph and cannot be written: its properties are ' .
            'restored from the persisted snapshot when the request ends. Persist the graph with ' .
            'persist($key, $object, mutable: true) to opt into shared mutation.',
            $className,
            $address,
        ));
    }

    /**
     * The class carries no such declared property slot (dynamic properties never exist here)
     */
    public static function unknownProperty(string $className, string $property): self
    {
        return new self(sprintf(
            'Class %s declares no property $%s with a storage slot; shared objects have no dynamic ' .
            'properties, and static or hooked (virtual) properties own no slot to write.',
            $className,
            $property,
        ));
    }

    /**
     * Array payloads stay sealed: a shared zend_array cannot grow, and growing it corrupts
     */
    public static function sealedArrayProperty(string $className, string $property): self
    {
        return new self(sprintf(
            'Property %s::$%s holds a sealed shared array and cannot be written. A zend_array in the ' .
            'arena cannot be grown - the engine would move its bucket block into one worker\'s private ' .
            'heap and write that pointer into the shared struct before aborting. Use ' .
            'Lisachenko\\SharedData\\Ipc\\SharedArray for a mutable shared collection.',
            $className,
            $property,
        ));
    }

    /**
     * A reference slot may only point at another object of the same arena
     */
    public static function targetNotShared(string $className, string $property): self
    {
        return new self(sprintf(
            'Property %s::$%s can only reference an object that is itself persisted in this arena: a ' .
            'request-heap object address means nothing in a sibling process. Persist the target first ' .
            '(persist($key, $object, mutable: true)) and write the instance persist() returned.',
            $className,
            $property,
        ));
    }

    /**
     * The value does not satisfy the property's declared type
     */
    public static function typeMismatch(string $className, string $property, string $declared, string $given): self
    {
        return new self(sprintf(
            'Property %s::$%s is declared %s and cannot hold a %s. The write API stores the value ' .
            'directly into the object slot, so the engine never gets the chance to coerce or to refuse ' .
            'it - the declared type is enforced here instead.',
            $className,
            $property,
            $declared,
            $given,
        ));
    }

    /**
     * A read asked for a shape the slot does not currently hold
     */
    public static function unexpectedSlotType(string $className, string $property, string $wanted, int $type): self
    {
        return new self(sprintf(
            'Property %s::$%s currently holds a value of zval type %d, which is not a %s; read it with ' .
            'the matching accessor, or with read() when the shape is not known up front.',
            $className,
            $property,
            $type,
            $wanted,
        ));
    }

    /**
     * One object cannot belong to a frozen and to a mutable graph at the same time
     */
    public static function modeConflict(string $className, string $memberClass, bool $wantsMutable): self
    {
        return new self(sprintf(
            'Cannot persist %s as %s: it reaches the already persisted %s, which belongs to a %s graph. ' .
            'One object cannot be both - a frozen graph rolls its members back at request end, which ' .
            'would silently undo what another process wrote through the mutable one. Drop the existing ' .
            'entry first, or persist both graphs in the same mode.',
            $className,
            $wantsMutable ? 'mutable' : 'frozen',
            $memberClass,
            $wantsMutable ? 'frozen' : 'mutable',
        ));
    }
}
