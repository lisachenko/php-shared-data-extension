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

namespace Lisachenko\SharedData\Shm;

/**
 * Every way the fork-shared arena can refuse to work
 *
 * Failure modes are named constructors, never hand-written messages at the call site:
 * the arena is memory that several processes share, so a message must always say WHICH
 * limit was hit and by how much - that wording belongs in one place.
 */
final class ArenaException extends \RuntimeException
{
    public static function unsupportedPlatform(string $platform): self
    {
        return new self(sprintf(
            'The shared arena is Linux-only (mmap MAP_ANONYMOUS|MAP_SHARED plus robust ' .
            'process-shared pthread mutexes); this platform is %s',
            $platform,
        ));
    }

    public static function libcUnavailable(string $reason): self
    {
        return new self("Cannot bind libc through FFI for the shared arena: {$reason}");
    }

    public static function mappingFailed(int $size): self
    {
        return new self(sprintf('mmap() of %d bytes of shared anonymous memory failed', $size));
    }

    public static function invalidSize(int $size): self
    {
        return new self(sprintf(
            'Arena size must be a positive multiple of the page size that leaves room for the ' .
            '%d byte header, got %d',
            Arena::HEADER_SIZE,
            $size,
        ));
    }

    public static function exhausted(int $requested, int $remaining): self
    {
        return new self(sprintf(
            'Shared arena exhausted: %d bytes requested, %d bytes left. The arena is fixed-size ' .
            'and bump-allocated (blocks are never returned); raise SHARED_DATA_ARENA_SIZE before ' .
            'the arena is created - it cannot grow once processes have forked.',
            $requested,
            $remaining,
        ));
    }

    public static function invalidAlignment(int $alignment): self
    {
        return new self(sprintf('Allocation alignment must be a power of two up to 4096, got %d', $alignment));
    }

    public static function outOfBounds(int $address, int $length): self
    {
        return new self(sprintf(
            'Address range [0x%x, 0x%x) is outside this arena',
            $address,
            $address + $length,
        ));
    }

    public static function misalignedAddress(int $address): self
    {
        return new self(sprintf('Address 0x%x is not 8-byte aligned; word access would tear', $address));
    }

    public static function rootNameTooLong(string $name): self
    {
        return new self(sprintf(
            'Named root "%s" is %d bytes long, the directory stores at most %d',
            $name,
            \strlen($name),
            Arena::ROOT_NAME_SIZE,
        ));
    }

    public static function rootsFull(string $name): self
    {
        return new self(sprintf(
            'The arena roots directory is full (%d entries); cannot register "%s"',
            Arena::ROOT_CAPACITY,
            $name,
        ));
    }

    public static function unknownRoot(string $name): self
    {
        return new self("The arena roots directory has no entry named \"{$name}\"");
    }

    public static function mutexSlotTooSmall(int $probed, int $slotSize): self
    {
        return new self(sprintf(
            'This platform reports a %d byte pthread_mutex_t, the arena reserves %d bytes per slot',
            $probed,
            $slotSize,
        ));
    }

    public static function invalidMutexIndex(int $index): self
    {
        return new self(sprintf(
            'Mutex index %d is out of range; the arena carries %d slots and reserves indexes 0..%d',
            $index,
            Arena::MUTEX_COUNT,
            Arena::FIRST_STRIPE - 1,
        ));
    }

    public static function mutexOperationFailed(string $operation, int $index, int $errorCode): self
    {
        return new self(sprintf(
            '%s on the arena mutex at slot/address %d failed with error %d; the shared lock state is unusable',
            $operation,
            $index,
            $errorCode,
        ));
    }

    public static function layoutMismatch(int $found, int $expected): self
    {
        return new self(sprintf(
            'The mapped arena carries layout version %d, this build speaks %d',
            $found,
            $expected,
        ));
    }

    public static function invalidRegistryCapacity(int $entryCapacity, int $objectCapacity): self
    {
        return new self(sprintf(
            'Arena registry capacities must be whole numbers of at least %d, got %d entries and %d objects',
            ArenaRegistryLayout::MINIMUM_TABLE_CAPACITY,
            $entryCapacity,
            $objectCapacity,
        ));
    }

    public static function registryTableFull(string $table, int $capacity): self
    {
        return new self(sprintf(
            'The arena registry table "%s" is full: all %d bucket slots are used, and growing it ' .
            'would make the engine reallocate arena memory into this worker\'s private heap. ' .
            'Size the registry with %s / %s before the workers fork.',
            $table,
            $capacity,
            ArenaRegistryLayout::ENTRY_CAPACITY_ENV,
            ArenaRegistryLayout::OBJECT_CAPACITY_ENV,
        ));
    }

    public static function registryTableRelocated(string $table, int $dataAddress): self
    {
        return new self(sprintf(
            'The bucket storage of the arena registry table "%s" now lives at 0x%x, outside the ' .
            'arena: the engine has grown the table into a private heap, and every process but the ' .
            'one that grew it is looking at memory that is not shared (and may already be freed). ' .
            'The registry is unusable - restart the worker pool with a larger capacity.',
            $table,
            $dataAddress,
        ));
    }

    public static function foreignArena(int $expected, int $found): self
    {
        return new self(sprintf(
            'The persistent module is anchored to the arena at 0x%x, but 0x%x was passed; a worker ' .
            'can only attach the arena its module globals were written for',
            $expected,
            $found,
        ));
    }

    public static function released(): self
    {
        return new self('This arena has already been unmapped by its creating process');
    }

    public static function notAnArena(int $magic): self
    {
        return new self(sprintf('The mapped region does not start with the arena magic (found 0x%x)', $magic));
    }
}
