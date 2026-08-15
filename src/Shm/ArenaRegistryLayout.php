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
 * How much room the registry reserves in the arena, and under which names it is published
 *
 * Every table of an arena-resident registry is PRE-SIZED at creation, because the engine
 * grows a full hashtable by reallocating its data block - which, for a block inside the
 * arena, would silently move shared state into one worker's private heap. Capacity is
 * therefore a deployment decision, taken once before the workers fork, and hitting it is a
 * clean typed failure rather than corruption.
 *
 * The names are what a child uses to FIND the registry: a forked worker inherits the arena
 * mapping and nothing else, so it looks the root tables up in the arena's own roots
 * directory instead of relying on any inherited PHP value.
 */
final class ArenaRegistryLayout
{
    /**
     * HT_MIN_SIZE: the smallest bucket count the engine addresses a table with
     */
    public const int MINIMUM_TABLE_CAPACITY = 8;

    /**
     * Roots-directory names of the registry tables
     */
    public const string ROOT_TABLE   = 'registry.root';
    public const string ROOT_ENTRIES = 'registry.entries';
    public const string ROOT_OBJECTS = 'registry.objects';

    /**
     * Named graphs (persist() keys) an arena registry can hold
     */
    public const int DEFAULT_ENTRY_CAPACITY = 256;

    /**
     * Object clones an arena registry can hold across all of its graphs
     */
    public const int DEFAULT_OBJECT_CAPACITY = 4096;

    public const string ENTRY_CAPACITY_ENV  = 'SHARED_DATA_ENTRY_CAPACITY';
    public const string OBJECT_CAPACITY_ENV = 'SHARED_DATA_OBJECT_CAPACITY';

    /**
     * Records are tiny fixed-shape tables (an entry record has 2 keys, an object record 6)
     */
    public const int RECORD_CAPACITY = self::MINIMUM_TABLE_CAPACITY;

    public function __construct(
        public readonly int $entryCapacity = self::DEFAULT_ENTRY_CAPACITY,
        public readonly int $objectCapacity = self::DEFAULT_OBJECT_CAPACITY,
    ) {
        if ($entryCapacity < self::MINIMUM_TABLE_CAPACITY || $objectCapacity < self::MINIMUM_TABLE_CAPACITY) {
            throw ArenaException::invalidRegistryCapacity($entryCapacity, $objectCapacity);
        }
    }

    /**
     * Reads the capacities from the environment, so a deployment can size them without code
     */
    public static function fromEnvironment(): self
    {
        return new self(
            self::readCapacity(self::ENTRY_CAPACITY_ENV, self::DEFAULT_ENTRY_CAPACITY),
            self::readCapacity(self::OBJECT_CAPACITY_ENV, self::DEFAULT_OBJECT_CAPACITY),
        );
    }

    private static function readCapacity(string $variable, int $default): int
    {
        $configured = getenv($variable);
        if ($configured === false || trim($configured) === '') {
            return $default;
        }
        if (preg_match('/^\d+$/', trim($configured)) !== 1) {
            throw ArenaException::invalidRegistryCapacity(0, 0);
        }

        return (int) trim($configured);
    }
}
