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
 * A closure was offered to - or recovered from - the shared area outside its contract
 *
 * Sharing a closure is decided by PROVENANCE and never by inspection: spike S17 observed a
 * post-fork closure address that held a different, perfectly valid Closure and executed the
 * wrong function (EPIC #15, correction #8), so no property of the object can establish that
 * following its address is safe. The provenance this package accepts is registration before
 * the fork barrier, and every failure below is a wiring mistake in that protocol - registering
 * too late, registering from a worker instead of from the process that owns the arena, or
 * capturing state that cannot mean the same thing in two processes.
 */
final class ClosureProvenanceException extends \RuntimeException
{
    public static function afterBarrier(string $name, int $barrierPid): self
    {
        return new self(sprintf(
            'Closure "%s" cannot be registered: process %d has already marked the fork barrier, and a ' .
            'closure compiled after it is exactly the case that cannot be shared - its op_array lives ' .
            'in one worker\'s private heap, where a sibling following the address may find unrelated ' .
            'memory that still passes for a Closure. Register every shared closure before ' .
            'markForkBarrier(), or hand the work over as a shared Task object.',
            $name,
            $barrierPid,
        ));
    }

    public static function notCreator(string $name, int $creatorPid, int $currentPid): self
    {
        return new self(sprintf(
            'Closure "%s" cannot be registered by process %d: the closure table belongs to process %d, ' .
            'which mapped the arena. Only that process registers, and only before it forks - a closure ' .
            'created in a worker is private to it however early the worker runs.',
            $name,
            $currentPid,
            $creatorPid,
        ));
    }

    public static function duplicateName(string $name): self
    {
        return new self(sprintf(
            'Closure "%s" is already registered. A name is bound to one closure address for the life of ' .
            'the arena, because a sibling may be holding that address right now; register the second ' .
            'implementation under its own name.',
            $name,
        ));
    }

    public static function invalidName(string $name, int $maxLength): self
    {
        return new self(sprintf(
            'A shared closure name must be 1..%d bytes, got %d ("%s"); the name is stored inline in a ' .
            'fixed-size arena record so that a child can find a closure with nothing but the mapping.',
            $maxLength,
            \strlen($name),
            $name,
        ));
    }

    public static function tableFull(int $capacity): self
    {
        return new self(sprintf(
            'All %d closure records are used. The table is pre-sized in the arena and never grows ' .
            '(growing a shared structure would move it into one worker\'s private heap); create it with ' .
            'a larger capacity before the workers fork.',
            $capacity,
        ));
    }

    public static function unknownName(string $name): self
    {
        return new self(sprintf(
            'No closure is registered under the name "%s"; a worker can only reach closures the ' .
            'arena-owning process registered before the fork barrier.',
            $name,
        ));
    }

    public static function notARecord(int $address): self
    {
        return new self(sprintf(
            'Address 0x%x is not a closure record of this table: only an address handed out by ' .
            'registerSharedClosure() (or read back from a value record) identifies a shared closure, ' .
            'and everything else is refused before anything is dereferenced.',
            $address,
        ));
    }

    public static function recordDrifted(int $address): self
    {
        return new self(sprintf(
            'The closure record at 0x%x no longer describes the closure it was written for: the object ' .
            'at the recorded address carries a different zend_function. That witness is an INTEGRITY ' .
            'check on our own record, never the acceptance test - acceptance is registration - and a ' .
            'mismatch means the pre-fork image was torn down, so nothing is dereferenced.',
            $address,
        ));
    }

    public static function boundThisNotShared(string $name, string $className): self
    {
        return new self(sprintf(
            'Closure "%s" is bound to an instance of %s that this family does not share. A bound $this ' .
            'is dereferenced by the closure in whichever worker invokes it, so it must be null or an ' .
            'object living in the arena: persist it first with %s::persist(%s::class, $object) and bind ' .
            'the instance that call returns.',
            $name,
            $className,
            PersistentStore::class,
            $className,
        ));
    }

    public static function capturedByReference(string $name, string $variable): self
    {
        return new self(sprintf(
            'Closure "%s" captures $%s by reference. A reference is a slot in the creating process\'s ' .
            'request memory: after the fork every worker writes its own copy-on-write copy of it, so ' .
            'the write is silently invisible everywhere else. Capture the value instead and publish ' .
            'changes through a shared object, a SharedArray or an AtomicInt.',
            $name,
            $variable,
        ));
    }

    public static function carriesStaticState(string $name, string $variable): self
    {
        return new self(sprintf(
            'Closure "%s" declares the static variable $%s. Static state lives in the function\'s own ' .
            'per-request table, which every worker copy-on-writes separately, so a counter kept there ' .
            'would count each process on its own with nothing reporting the divergence. Keep the state ' .
            'in the arena (AtomicInt, SharedArray, a shared mutable object) and let the closure be pure.',
            $name,
            $variable,
        ));
    }
}
