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

use PHPUnit\Framework\TestCase;

/**
 * Single-process behaviour of the arena: allocation, bookkeeping, roots and guards
 *
 * Cross-process behaviour - the whole point of the thing - is covered by ArenaForkTest.
 * Arenas here are deliberately small: they are never unmapped before the process ends
 * (only the creator unmaps, at shutdown), so a test that maps 64 MB per case would keep
 * every one of them for the whole run.
 */
class ArenaTest extends TestCase
{
    private const int TEST_SIZE = 1 << 20;

    private function makeArena(?int $size = null): Arena
    {
        return Arena::create($size ?? self::TEST_SIZE);
    }

    public function testFreshArenaStartsEmptyAboveItsHeader(): void
    {
        $arena = $this->makeArena();

        $this->assertSame(self::TEST_SIZE, $arena->size());
        $this->assertSame(self::TEST_SIZE - Arena::HEADER_SIZE, $arena->capacity());
        $this->assertSame(0, $arena->watermark());
        $this->assertSame(self::TEST_SIZE - Arena::HEADER_SIZE, $arena->remaining());
        $this->assertTrue($arena->isCreator());
        $this->assertSame(getmypid(), $arena->creatorPid());
    }

    public function testAFreshArenaPassesItsOwnHeaderCheck(): void
    {
        $arena = $this->makeArena();

        // Magic plus layout version: what a recovering worker verifies before it trusts a
        // single offset inside the inherited mapping
        $arena->assertIntact();

        $this->assertSame(Arena::LAYOUT_VERSION, 1);
    }

    public function testMutexSlotHoldsThisPlatformsMutex(): void
    {
        $arena = $this->makeArena();

        // Measured, not assumed: 40 on x86-64/arm64 glibc, and never more than the slot
        $this->assertGreaterThanOrEqual(Arena::MUTEX_SIZE_FLOOR, $arena->mutexSize());
        $this->assertLessThanOrEqual(Arena::MUTEX_SLOT_SIZE, $arena->mutexSize());
        $this->assertSame(Arena::MUTEX_COUNT - Arena::FIRST_STRIPE, $arena->stripeCount());
    }

    public function testAllocationsAreDisjointAlignedAndInsideTheArena(): void
    {
        $arena = $this->makeArena();

        $first  = $arena->allocate(24);
        $second = $arena->allocate(24);
        $page   = $arena->allocate(8, 4096);

        $this->assertSame(0, $first % 16);
        $this->assertSame(0, $second % 16);
        $this->assertSame(0, $page % 4096);
        $this->assertGreaterThanOrEqual($first + 24, $second);
        $this->assertGreaterThanOrEqual($arena->baseAddress() + Arena::HEADER_SIZE, $first);
        $this->assertLessThan($arena->baseAddress() + $arena->size(), $page);
    }

    public function testWatermarkAccountsForEveryAllocationAndNeverFalls(): void
    {
        $arena = $this->makeArena();

        $this->assertSame(0, $arena->watermark());
        $arena->allocate(1000);
        $afterFirst = $arena->watermark();
        $this->assertGreaterThanOrEqual(1000, $afterFirst);

        $arena->allocate(1000);
        $this->assertGreaterThanOrEqual($afterFirst + 1000, $arena->watermark());
        $this->assertSame($arena->size() - Arena::HEADER_SIZE - $arena->watermark(), $arena->remaining());
    }

    public function testExhaustionThrowsATypedExceptionAndKeepsTheArenaUsable(): void
    {
        $arena = $this->makeArena();
        $left  = $arena->remaining();

        try {
            $arena->allocate($left + 1);
            $this->fail('An over-sized allocation must not succeed');
        } catch (ArenaException $exception) {
            $this->assertStringContainsString('Shared arena exhausted', $exception->getMessage());
        }

        // The refused allocation moved nothing: the arena is exactly as it was
        $this->assertSame($left, $arena->remaining());
        $this->assertGreaterThan(0, $arena->allocate(16));
    }

    public function testAllocationRejectsNonsensicalSizeAndAlignment(): void
    {
        $arena = $this->makeArena();

        $this->expectException(ArenaException::class);
        $arena->allocate(64, 24);
    }

    public function testWordAndByteAccessRoundTrip(): void
    {
        $arena   = $this->makeArena();
        $address = $arena->allocate(64);

        $arena->writeWord($address, 0x0123456789);
        $this->assertSame(0x0123456789, $arena->readWord($address));

        $this->assertSame(11, $arena->writeBytes($address + 8, 'hello arena'));
        $this->assertSame('hello arena', $arena->readBytes($address + 8, 11));
    }

    public function testAccessOutsideThePayloadIsRefused(): void
    {
        $arena = $this->makeArena();

        $this->expectException(ArenaException::class);
        // The header carries the cursor, the mutex bank and the roots directory: userland
        // byte access must never reach it
        $arena->writeWord($arena->baseAddress(), 1);
    }

    public function testMisalignedWordAccessIsRefused(): void
    {
        $arena   = $this->makeArena();
        $address = $arena->allocate(64);

        $this->expectException(ArenaException::class);
        $arena->readWord($address + 1);
    }

    public function testNamedRootsAreStoredLookedUpAndOverwritten(): void
    {
        $arena   = $this->makeArena();
        $entries = $arena->allocate(64);
        $objects = $arena->allocate(64);

        $arena->putRoot('registry.entries', $entries);
        $arena->putRoot('registry.objects', $objects);

        $this->assertSame($entries, $arena->findRoot('registry.entries'));
        $this->assertSame($objects, $arena->requireRoot('registry.objects'));
        $this->assertNull($arena->findRoot('registry.nothing'));
        $this->assertSame(
            ['registry.entries' => $entries, 'registry.objects' => $objects],
            $arena->roots(),
        );

        $arena->putRoot('registry.entries', $objects);
        $this->assertSame($objects, $arena->findRoot('registry.entries'));
        $this->assertCount(2, $arena->roots());
    }

    public function testUnknownRequiredRootThrows(): void
    {
        $arena = $this->makeArena();

        $this->expectException(ArenaException::class);
        $arena->requireRoot('registry.entries');
    }

    public function testRootNamesLongerThanTheDirectorySlotAreRefused(): void
    {
        $arena = $this->makeArena();

        $this->expectException(ArenaException::class);
        $arena->putRoot(str_repeat('n', Arena::ROOT_NAME_SIZE + 1), $arena->allocate(8));
    }

    public function testRootsDirectoryIsFixedSizeAndSaysSoWhenFull(): void
    {
        $arena   = $this->makeArena();
        $address = $arena->allocate(8);

        for ($index = 0; $index < Arena::ROOT_CAPACITY; $index++) {
            $arena->putRoot("root.{$index}", $address);
        }
        $this->assertCount(Arena::ROOT_CAPACITY, $arena->roots());

        $this->expectException(ArenaException::class);
        $arena->putRoot('one.too.many', $address);
    }

    public function testStripeMutexesLockAndUnlockWithinTheirRange(): void
    {
        $arena = $this->makeArena();

        $this->assertFalse($arena->lockStripe(Arena::FIRST_STRIPE));
        $arena->unlockStripe(Arena::FIRST_STRIPE);

        $this->assertTrue($arena->tryLockStripe(Arena::FIRST_STRIPE));
        $arena->unlockStripe(Arena::FIRST_STRIPE);
    }

    public function testReservedMutexSlotsAreNotHandedOutAsStripes(): void
    {
        $arena = $this->makeArena();

        $this->expectException(ArenaException::class);
        $arena->lockStripe(Arena::ALLOCATOR_MUTEX);
    }

    public function testSizeIsConfigurableThroughTheEnvironment(): void
    {
        putenv(Arena::SIZE_ENV . '=2M');

        try {
            $this->assertSame(2 * 1024 * 1024, Arena::configuredSize());
        } finally {
            putenv(Arena::SIZE_ENV);
        }

        $this->assertSame(Arena::DEFAULT_SIZE, Arena::configuredSize());
    }

    public function testUnalignedTotalSizeIsRefused(): void
    {
        $this->expectException(ArenaException::class);
        Arena::create(Arena::HEADER_SIZE + 1);
    }
}
