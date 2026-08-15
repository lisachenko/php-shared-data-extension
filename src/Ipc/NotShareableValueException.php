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

/**
 * A value was handed to the shared area that cannot cross a worker boundary
 *
 * Every message here names the REMEDY, because the alternative to a shareable value is
 * never "give up": a plain array becomes a SharedArray, an ordinary object becomes a shared
 * one through PersistentStore::persist(), a closure becomes a Task object. The one thing
 * that is never offered is serialization - encoding the value into bytes is precisely what
 * this package exists to avoid, so a value that cannot travel by address does not travel.
 */
final class NotShareableValueException extends \InvalidArgumentException
{
    public static function plainArray(): self
    {
        return new self(sprintf(
            'A plain PHP array cannot be shared: its bucket storage is grown by the engine into the ' .
            'private heap of whichever worker writes to it, so siblings would follow a pointer into ' .
            'foreign memory. Copy the elements into a %s of fixed capacity and share that instead.',
            SharedArray::class,
        ));
    }

    public static function closure(): self
    {
        return new self(sprintf(
            'This Closure is not registered as a shared one. Sharing a closure by address is only safe ' .
            'when the function was compiled BEFORE the fork barrier, and that provenance cannot be ' .
            'recovered from the object itself: spike S17 observed a post-fork closure address that held ' .
            'a different, perfectly valid Closure and executed the wrong function. So provenance is ' .
            'recorded rather than inferred - call %s::registerSharedClosure($name, $closure) in the ' .
            'process that owns the arena, before it forks, and the closure travels as a record address ' .
            'from then on. A closure created after the fork cannot be registered at all: hand that work ' .
            'over as a shared Task object naming what to do instead of the callable that does it.',
            ClosureProvenance::class,
        ));
    }

    public static function resource(): self
    {
        return new self(
            'A resource cannot be shared: it is an index into a per-process table of engine state ' .
            '(file handles, sockets, contexts) and means nothing in another worker. Open the resource ' .
            'in the worker that uses it, or hand over the descriptor with the notification socket.',
        );
    }

    public static function foreignObject(string $className): self
    {
        return new self(sprintf(
            'An instance of %s is an ordinary request object and cannot be shared: its zend_object ' .
            'lives in this worker\'s private heap. Move it into the arena first with ' .
            '%s::persist(%s::class, $object) and share the instance that call returns - it is the ' .
            'canonical shared one, and its address means the same thing in every process of the family.',
            $className,
            PersistentStore::class,
            $className,
        ));
    }

    public static function withoutStore(string $className): self
    {
        return new self(sprintf(
            'An instance of %s cannot be shared through a codec that has no %s: object records carry ' .
            'the address of a shared object, and only the store\'s registry can tell whether an ' .
            'address is one of ours. Build the codec with the arena-backed store that persisted the ' .
            'object (PersistentStore::bootShared()).',
            $className,
            PersistentStore::class,
        ));
    }

    public static function unsupportedType(string $type): self
    {
        return new self(sprintf(
            'Values of type %s cannot cross a worker boundary; the shared area carries null, bool, ' .
            'int, float, arena strings, shared objects and shared arrays.',
            $type,
        ));
    }

    public static function foreignAddress(int $address): self
    {
        return new self(sprintf(
            'Address 0x%x is not inside this arena, so it cannot be part of a value record; a record ' .
            'may only reference memory every process of the family maps at the same address.',
            $address,
        ));
    }
}
