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

use Lisachenko\SharedData\PersistedEntry;
use Lisachenko\SharedData\Registry;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * The registry-in-arena: pre-sized tables the engine is never allowed to grow
 *
 * A registry table that grows is not a performance problem, it is silent corruption: the
 * engine reallocates the bucket block into the private heap of whichever process filled
 * the table, writes that address into the SHARED struct and carries on, so every sibling
 * keeps reading a pointer into memory it does not own. The guard therefore has to fire on
 * the insert, before anything moves - and the recovery path has to be able to notice a
 * block that has left the arena.
 */
class ArenaRegistryTest extends TestCase
{
    private const int ARENA_SIZE = 4 << 20;

    /**
     * @return array{0: Arena, 1: ArenaAllocator}
     */
    private function makeArena(): array
    {
        $arena = Arena::create(self::ARENA_SIZE);

        return [$arena, new ArenaAllocator($arena)];
    }

    public function testLayoutVersionIsFour(): void
    {
        // The version the module globals are checked against; arena tables are what v4 adds
        $this->assertSame(4, Registry::LAYOUT_VERSION);
    }

    public function testTablesArePublishedInTheArenaRootsDirectory(): void
    {
        [$arena, $allocator] = $this->makeArena();

        [$registry, $base] = Registry::createInArena($allocator);

        $this->assertTrue($registry->isArenaBacked());
        $this->assertSame($arena->baseAddress(), $base, 'module globals must anchor the arena, not the registry');

        foreach ([
            ArenaRegistryLayout::ROOT_TABLE,
            ArenaRegistryLayout::ROOT_ENTRIES,
            ArenaRegistryLayout::ROOT_OBJECTS,
        ] as $name) {
            $address = $arena->findRoot($name);
            $this->assertNotNull($address, "{$name} was not published");
            $this->assertTrue($arena->contains($address, 8), "{$name} is not arena memory");
        }
    }

    public function testRegistryIsRecoveredFromTheArenaAlone(): void
    {
        [, $allocator] = $this->makeArena();

        [$registry] = Registry::createInArena($allocator);
        $registry->store('Graph\\First', new PersistedEntry([], []));
        $registry->store('Graph\\Second', new PersistedEntry([], []));

        // Everything a forked child has: the mapping, and the names in its roots directory
        $recovered = Registry::fromArena($allocator);

        $this->assertTrue($recovered->isArenaBacked());
        $this->assertTrue($recovered->has('Graph\\First'));
        $this->assertTrue($recovered->has('Graph\\Second'));
        $this->assertSame(['Graph\\First', 'Graph\\Second'], $recovered->names());
    }

    public function testEntriesTableRefusesToGrowAndSaysWhichTableFilledUp(): void
    {
        [, $allocator] = $this->makeArena();

        [$registry] = Registry::createInArena($allocator, new ArenaRegistryLayout(8, 8));

        for ($index = 0; $index < 8; $index++) {
            $registry->store("Graph\\Number{$index}", new PersistedEntry([], []));
        }
        $this->assertCount(8, $registry->names());

        $this->expectException(ArenaException::class);
        $this->expectExceptionMessageMatches('/registry table "entries" is full/');

        $registry->store('Graph\\OneTooMany', new PersistedEntry([], []));
    }

    public function testRefusedInsertLeavesTheTableWhereItWas(): void
    {
        [$arena, $allocator] = $this->makeArena();

        [$registry] = Registry::createInArena($allocator, new ArenaRegistryLayout(8, 8));
        for ($index = 0; $index < 8; $index++) {
            $registry->store("Graph\\Number{$index}", new PersistedEntry([], []));
        }

        $entriesAddress = $arena->requireRoot(ArenaRegistryLayout::ROOT_ENTRIES);
        $before         = $this->dataBlockAddress($entriesAddress);

        try {
            $registry->store('Graph\\OneTooMany', new PersistedEntry([], []));
        } catch (ArenaException) {
            // expected
        }

        // The one observable symptom of a resize is a changed data-block address; the
        // refusal must leave it exactly where it was, inside the arena
        $this->assertSame($before, $this->dataBlockAddress($entriesAddress));
        $this->assertTrue($arena->contains($before, 8));
        $this->assertCount(8, $registry->names());

        // ... and recovery still accepts the registry
        $this->assertCount(8, Registry::fromArena($allocator)->names());
    }

    public function testUpsertOfAnExistingKeyIsAllowedOnAFullTable(): void
    {
        [, $allocator] = $this->makeArena();

        [$registry] = Registry::createInArena($allocator, new ArenaRegistryLayout(8, 8));
        for ($index = 0; $index < 8; $index++) {
            $registry->store("Graph\\Number{$index}", new PersistedEntry([], []));
        }

        // Replacing a graph consumes no bucket slot, so a full table must still accept it -
        // otherwise a worker could never re-persist anything once the registry filled up
        $registry->store('Graph\\Number3', new PersistedEntry([], []));

        $this->assertCount(8, $registry->names());
    }

    public function testHeapRegistryIsUnaffectedByAnyOfThis(): void
    {
        [$registry] = Registry::create();

        $this->assertFalse($registry->isArenaBacked());

        // No capacity anywhere in sight: heap tables grow exactly as they did in v3
        for ($index = 0; $index < 64; $index++) {
            $registry->store("Graph\\Heap{$index}", new PersistedEntry([], []));
        }
        $this->assertCount(64, $registry->names());
    }

    /**
     * Address of a table's bucket block, the way the engine's HT_GET_DATA_ADDR computes it
     */
    private function dataBlockAddress(int $tableAddress): int
    {
        $raw = Core::pointerAtAddress('HashTable *', $tableAddress);

        $mask = $raw->nTableMask;
        if ($mask > 0x7FFFFFFF) {
            $mask -= 0x100000000;
        }

        return Core::addressOf($raw->arData) + $mask * 4;
    }
}
