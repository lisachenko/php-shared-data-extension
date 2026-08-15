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

use ZEngine\Memory\Allocator;
use ZEngine\Type\PersistentHashTable;

/**
 * The arena, seen through z-engine's allocator seam
 *
 * z-engine mints its persistent primitives (hashtable structs, object clones, interned
 * string blocks) through a ZEngine\Memory\Allocator. The default one is malloc-backed and
 * therefore PROCESS-LOCAL: memory a worker allocates after the fork is invisible to its
 * parent and its siblings, which is exactly what stops persisted objects from being shared.
 * This adapter answers the same interface out of the fork-shared arena instead, so a
 * structure built through it lives at an address that means the same thing in every
 * process of the family.
 *
 * Two properties of the arena make it a legal Allocator:
 *
 *  - blocks come back ZEROED, because the bump allocator never recycles: every address it
 *    hands out is untouched mmap memory, which the kernel guarantees to be zero-filled;
 *  - ownsAllocations() is true, so z-engine never frees an individual block through its own
 *    allocator. Arena memory is reclaimed as one region when the creating process exits -
 *    a structure built here refuses its destroy() path instead of calling free(3) on an
 *    address the process heap knows nothing about.
 */
final class ArenaAllocator implements Allocator
{
    public function __construct(private readonly Arena $arena)
    {
    }

    /**
     * The arena this allocator hands memory out of
     */
    public function arena(): Arena
    {
        return $this->arena;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function allocate(int $size, int $alignment = Allocator::DEFAULT_ALIGNMENT): int
    {
        // Never below malloc's guarantee: z-engine asks for ENGINE_STRUCT_ALIGNMENT (8),
        // which is all a zend_object needs, but keeping every arena block 16-aligned costs
        // nothing in a bump allocator and keeps engine structures aligned the way the
        // process heap would have aligned them
        return $this->arena->allocate($size, max($alignment, Allocator::DEFAULT_ALIGNMENT));
    }

    /**
     * The arena owns every block it hands out: z-engine must never free one
     *
     * @inheritDoc
     */
    #[\Override]
    public function ownsAllocations(): bool
    {
        return true;
    }

    /**
     * Mints an arena-resident hashtable whose bucket storage is pre-sized and NEVER grown
     *
     * Both halves of a shared table come from the arena: the struct through this allocator,
     * the bucket block through externalStorageSize()/withExternalStorage(). Pre-sizing is
     * not an optimization but the whole point - the engine grows a full table by
     * perealloc()ing its data block, and for arena memory that call would hand the block
     * to the process heap of whichever worker happened to trigger it. A table installed
     * with external storage refuses the insert that would start the growth instead, with
     * z-engine's typed storageCapacityExhausted() exception.
     *
     * @param int $capacity Number of buckets to reserve; rounded up to the power of two
     *                      the engine addresses tables in
     */
    public function createTable(int $capacity): PersistentHashTable
    {
        $capacity = self::bucketCapacity($capacity);
        $address  = $this->allocate(
            PersistentHashTable::externalStorageSize($capacity),
            Allocator::ENGINE_STRUCT_ALIGNMENT,
        );

        return PersistentHashTable::withExternalStorage($address, $capacity, $this);
    }

    /**
     * Rounds a wanted number of buckets up to a power of two of at least HT_MIN_SIZE
     */
    public static function bucketCapacity(int $wanted): int
    {
        $capacity = ArenaRegistryLayout::MINIMUM_TABLE_CAPACITY;
        while ($capacity < $wanted) {
            $capacity <<= 1;
        }

        return $capacity;
    }
}
