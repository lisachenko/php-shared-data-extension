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

/**
 * Structural failures of the shared IPC primitives
 *
 * These are the fixed-capacity limits the shared area is built out of: a wake registry with
 * a slot per process, a slot table sized before the fork, waiter tables sized per structure.
 * Nothing here grows on demand - growing a shared structure means reallocating it, and a
 * reallocation would move it into one worker's private heap - so hitting a limit is a
 * typed, actionable failure that names the knob instead of silent corruption.
 */
final class IpcException extends \RuntimeException
{
    public static function wakeRegistryFull(int $capacity): self
    {
        return new self(sprintf(
            'Every one of the %d wake slots is claimed: this worker family has more processes than ' .
            'the notification plane was created for. Size it with WakeRegistry::create($arena, $slots) ' .
            'BEFORE forking - the socket pairs must exist at fork time to be inherited.',
            $capacity,
        ));
    }

    public static function wakeRegistryNotInherited(): self
    {
        return new self(
            'This wake registry has no socket pair for the current process. A registry must be created ' .
            'before any worker forks, so every process inherits every pair; a process that was not ' .
            'forked from the creator cannot be notified (descriptors are per-process, and the arena ' .
            'carries addresses, not file handles).',
        );
    }

    public static function slotTableFull(int $capacity, int $outstanding, int $retired): self
    {
        return new self(sprintf(
            'All %d result slots are in use (%d outstanding, %d retired). Slots are recycled through ' .
            'a free list, so a slot only comes back when its owner calls releaseSlot() - a handle ' .
            'that is never awaited never gives its slot back. Either release what is settled, or ' .
            'pre-size the table larger before the workers fork; it is allocated in the arena and ' .
            'never grows.',
            $capacity,
            $outstanding,
            $retired,
        ));
    }

    public static function unknownSlot(int $index, int $capacity): self
    {
        return new self(sprintf(
            'Result slot %d does not exist; the table holds slots 0..%d',
            $index,
            $capacity - 1,
        ));
    }

    /**
     * A handle that names a slot the table has already handed to somebody else
     *
     * This is the whole safety property of recycling: a slot id is only half a claim, and the
     * generation is the other half. Answering such a handle with the slot's current contents
     * would hand one task's result to another task's waiter, silently and plausibly.
     */
    public static function staleSlot(int $index, int $held, int $current): self
    {
        return new self(sprintf(
            'Result slot %d is at generation %d and this handle holds generation %d: the slot was ' .
            'released and handed to another task. A recycled slot never answers an older handle - ' .
            'read the result before releasing it, and do not keep the id afterwards.',
            $index,
            $current,
            $held,
        ));
    }

    /**
     * A slot that has been used up: its generation counter reached the end of the ticket layout
     */
    public static function slotRetired(int $index): self
    {
        return new self(sprintf(
            'Result slot %d is retired: its generation counter reached the end of the 16 bits a slot ' .
            'ticket carries, so the slot was taken out of circulation instead of wrapping round to a ' .
            'generation an old handle could match.',
            $index,
        ));
    }

    public static function slotAlreadyCompleted(int $index, int $generation): self
    {
        return new self(sprintf(
            'Result slot %d is already completed in generation %d. A slot is written exactly once per ' .
            'generation - allocate a new one for the next result rather than reusing a settled slot.',
            $index,
            $generation,
        ));
    }

    /**
     * A release of a slot whose answer has not been written yet
     *
     * Recycling a pending slot would hand a live record to the next task while the process that
     * owes the answer is still going to write it - the one way this design could return another
     * task's result. Refusing is the only correct answer.
     */
    public static function slotNotSettled(int $index): self
    {
        return new self(sprintf(
            'Result slot %d is still pending and cannot be released: the process that owes its answer ' .
            'may still write it, and recycling the slot now would let that write land on another ' .
            'task. Release a slot only once it has settled and its result has been read.',
            $index,
        ));
    }

    public static function capacityTooLarge(string $structure, int $capacity, int $maximum): self
    {
        return new self(sprintf(
            '%s capacity is %d, above the %d a slot ticket can address: an id carries the slot index ' .
            'and its generation in the 32 bits a wake event has for it, and a wider table would ' .
            'truncate ids rather than fail',
            $structure,
            $capacity,
            $maximum,
        ));
    }

    /**
     * A table in the arena whose record shape is not the one this build reads
     *
     * `ResultSlotTable` is a consumer structure published in the roots directory, so it does not
     * ride `Registry::LAYOUT_VERSION`; this word is its own guard, and it exists because reading
     * the wrong slot geometry at the right address is silent rather than fatal.
     */
    public static function slotTableFormat(int $found, int $expected): self
    {
        return new self(sprintf(
            'The result slot table in the arena is format %d and this build reads format %d. Slots ' .
            'are addressed by offset, so a mismatched shape is read at the right address with the ' .
            'wrong meaning - the whole family has to run one build of this package.',
            $found,
            $expected,
        ));
    }

    public static function invalidCapacity(string $structure, int $capacity): self
    {
        return new self(sprintf('%s capacity must be a positive number of records, got %d', $structure, $capacity));
    }

    public static function outOfRange(int $index, int $capacity): self
    {
        return new self(sprintf(
            'Index %d is outside the shared array; it holds %d fixed slots (0..%d) and cannot grow',
            $index,
            $capacity,
            $capacity - 1,
        ));
    }

    public static function negativeCounter(int $value): self
    {
        return new self(sprintf(
            'A wait group counter went negative (%d): done() was called more often than add(). The ' .
            'counter is shared, so the miscount belongs to the worker family, not to one process.',
            $value,
        ));
    }

    public static function notShared(string $structure, int $address): self
    {
        return new self(sprintf(
            'The %s at 0x%x is not inside the arena; only structures every process maps at the same ' .
            'address can be attached',
            $structure,
            $address,
        ));
    }
}
