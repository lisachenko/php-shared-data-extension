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

    public static function slotTableFull(int $capacity): self
    {
        return new self(sprintf(
            'All %d result slots are used. The slot table is pre-sized in the arena and never grows; ' .
            'create it with a larger capacity before the workers fork.',
            $capacity,
        ));
    }

    public static function unknownSlot(int $id, int $capacity): self
    {
        return new self(sprintf('Result slot %d does not exist; the table holds slots 0..%d', $id, $capacity - 1));
    }

    public static function slotAlreadyCompleted(int $id): self
    {
        return new self(sprintf(
            'Result slot %d is already completed. A slot is written exactly once - allocate a new one ' .
            'for the next result rather than reusing a settled slot.',
            $id,
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
