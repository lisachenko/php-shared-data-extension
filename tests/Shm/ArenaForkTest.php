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
 * The arena's reason to exist: real processes, one region of memory, no serialization
 *
 * Every case here forks with pcntl. A child answers by EXIT CODE (0 = the expectation
 * held, a per-case non-zero code says which expectation did not), and where a value has
 * to travel between processes it travels as raw bytes over a socket pair - an address is
 * eight bytes of `pack('P')`, never a serialized PHP value. That is the Never-Serialize
 * Rule the whole epic is built on, applied to its own test suite.
 *
 * Children terminate with an immediate `exit()` inside the forked copy of the PHPUnit
 * process; they never touch the result printer, and the parent is the only process that
 * asserts.
 */
class ArenaForkTest extends TestCase
{
    private const int TEST_SIZE = 4 << 20;

    /**
     * Exit codes children answer with, so a failure names the expectation that broke
     */
    private const int OK              = 0;
    private const int WRONG_WORD      = 11;
    private const int WRONG_BYTES     = 12;
    private const int WRONG_BASE      = 13;
    private const int CHILD_EXCEPTION = 40;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('ext-pcntl is required to exercise fork-shared memory');
        }
    }

    public function testChildrenReadValuesPersistedBeforeTheFork(): void
    {
        $arena   = Arena::create(self::TEST_SIZE);
        $address = $arena->allocate(64);
        $arena->writeWord($address, 0xC0FFEE);
        $arena->writeBytes($address + 8, 'shared before the fork');

        $base = $arena->baseAddress();

        // Two children, both reading the very same address the parent wrote to
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $children[] = $this->fork(static function () use ($arena, $address, $base): int {
                if ($arena->baseAddress() !== $base) {
                    return self::WRONG_BASE;
                }
                if ($arena->readWord($address) !== 0xC0FFEE) {
                    return self::WRONG_WORD;
                }

                return $arena->readBytes($address + 8, 22) === 'shared before the fork'
                    ? self::OK
                    : self::WRONG_BYTES;
            });
        }

        foreach ($children as $pid) {
            $this->assertSame(self::OK, $this->await($pid), 'a child disagreed about the pre-fork value');
        }
    }

    public function testValueAllocatedByAChildIsReachableByTheParentAndASibling(): void
    {
        $arena = Arena::create(self::TEST_SIZE);
        [$parentEnd, $childEnd] = $this->socketPair();

        $writer = $this->fork(static function () use ($arena, $childEnd): int {
            $address = $arena->allocate(64);
            $arena->writeWord($address, 0x5EED);
            $arena->writeBytes($address + 8, 'written after the fork');

            // The ONLY thing that crosses the process boundary: eight bytes of address
            socket_write($childEnd, pack('P', $address), 8);

            return self::OK;
        });
        socket_close($childEnd);

        $payload = (string) socket_read($parentEnd, 8, PHP_BINARY_READ);
        socket_close($parentEnd);
        $this->assertSame(8, \strlen($payload), 'the child did not report its allocation');

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $payload);
        $address  = $unpacked[1];

        $this->assertSame(self::OK, $this->await($writer));

        // The parent reads what a different process allocated and wrote
        $this->assertSame(0x5EED, $arena->readWord($address));
        $this->assertSame('written after the fork', $arena->readBytes($address + 8, 22));

        // ... and so does a sibling forked afterwards, from the same eight bytes
        $sibling = $this->fork(static function () use ($arena, $address): int {
            if ($arena->readWord($address) !== 0x5EED) {
                return self::WRONG_WORD;
            }

            return $arena->readBytes($address + 8, 22) === 'written after the fork'
                ? self::OK
                : self::WRONG_BYTES;
        });

        $this->assertSame(self::OK, $this->await($sibling), 'a sibling could not attach the address');
    }

    public function testConcurrentAllocationFromFourChildrenNeverOverlaps(): void
    {
        $arena          = Arena::create(self::TEST_SIZE);
        $blocksPerChild = 250;

        /** @var list<array{0: int, 1: resource}> $children */
        $children = [];
        for ($index = 0; $index < 4; $index++) {
            [$parentEnd, $childEnd] = $this->socketPair();

            $marker = 0x41 + $index;
            $pid    = $this->fork(static function () use ($arena, $childEnd, $marker, $blocksPerChild): int {
                $records = '';
                for ($block = 0; $block < $blocksPerChild; $block++) {
                    $size    = 8 + ($block % 41);
                    $address = $arena->allocate($size, 8);
                    $arena->writeBytes($address, str_repeat(\chr($marker), $size));
                    $records .= pack('PP', $address, $size);
                }
                socket_write($childEnd, $records);

                return self::OK;
            });
            socket_close($childEnd);

            $children[] = [$pid, $parentEnd, $marker];
        }

        /** @var list<array{0: int, 1: int, 2: int}> $blocks */
        $blocks = [];
        foreach ($children as [$pid, $socket, $marker]) {
            $payload = '';
            while (($chunk = socket_read($socket, 65536, PHP_BINARY_READ)) !== false && $chunk !== '') {
                $payload .= $chunk;
            }
            socket_close($socket);
            $this->assertSame(self::OK, $this->await($pid));

            for ($offset = 0; $offset < \strlen($payload); $offset += 16) {
                /** @var array{1: int, 2: int} $record */
                $record   = unpack('Paddress/Psize', substr($payload, $offset, 16));
                $blocks[] = [$record['address'], $record['size'], $marker];
            }
        }

        $this->assertCount(4 * $blocksPerChild, $blocks);

        usort($blocks, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $previousEnd = 0;
        foreach ($blocks as [$address, $size, $marker]) {
            $this->assertGreaterThanOrEqual($previousEnd, $address, 'two children were handed overlapping blocks');
            $previousEnd = $address + $size;

            // Every byte still carries the marker of the child that owns the block
            $this->assertSame(str_repeat(\chr($marker), $size), $arena->readBytes($address, $size));
        }
    }

    public function testWatermarkSeenByTheParentIncludesWhatChildrenAllocated(): void
    {
        $arena  = Arena::create(self::TEST_SIZE);
        $before = $arena->watermark();

        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $children[] = $this->fork(static function () use ($arena): int {
                $arena->allocate(4096, 8);

                return self::OK;
            });
        }
        foreach ($children as $pid) {
            $this->assertSame(self::OK, $this->await($pid));
        }

        // The cursor lives in the arena, not in a process: both children moved THIS one
        $this->assertGreaterThanOrEqual($before + 2 * 4096, $arena->watermark());
    }

    public function testNamedRootPublishedByAChildIsFoundByTheParent(): void
    {
        $arena = Arena::create(self::TEST_SIZE);

        $pid = $this->fork(static function () use ($arena): int {
            $address = $arena->allocate(32);
            $arena->writeWord($address, 0xBEEF);
            $arena->putRoot('child.published', $address);

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($pid));

        $address = $arena->findRoot('child.published');
        $this->assertNotNull($address);
        $this->assertSame(0xBEEF, $arena->readWord($address));
    }

    public function testOnlyTheCreatingProcessUnmapsTheArena(): void
    {
        $arena   = Arena::create(self::TEST_SIZE);
        $address = $arena->allocate(16);
        $arena->writeWord($address, 0x1234);

        $pid = $this->fork(static function () use ($arena): int {
            if ($arena->isCreator()) {
                return self::WRONG_BASE;
            }
            // A child unmapping the region would tear it out from under the family: no-op
            $arena->destroy();

            return $arena->readWord(0) === 0 ? self::WRONG_WORD : self::OK;
        });

        // The child's readWord(0) is out of bounds and throws, which is the CHILD_EXCEPTION
        // path - what matters is that the parent's arena is untouched afterwards
        $this->assertContains($this->await($pid), [self::OK, self::CHILD_EXCEPTION]);
        $this->assertSame(0x1234, $arena->readWord($address));
        $this->assertTrue($arena->isCreator());
    }

    public function testStripeLockIsRecoveredWhenItsOwnerIsKilled(): void
    {
        $arena  = Arena::create(self::TEST_SIZE);
        $signal = $arena->allocate(8);
        $arena->writeWord($signal, 0);

        $victim = $this->fork(static function () use ($arena, $signal): int {
            $arena->lockStripe(Arena::FIRST_STRIPE);
            $arena->writeWord($signal, 1);
            sleep(30);

            return self::OK;
        });

        while ($arena->readWord($signal) !== 1) {
            usleep(1_000);
        }
        posix_kill($victim, SIGKILL);
        pcntl_waitpid($victim, $status);

        // ROBUST: the next locker is told the owner died instead of blocking forever
        $this->assertTrue($arena->lockStripe(Arena::FIRST_STRIPE));
        $arena->unlockStripe(Arena::FIRST_STRIPE);

        // ... and the mutex is an ordinary working mutex again
        $this->assertFalse($arena->lockStripe(Arena::FIRST_STRIPE));
        $arena->unlockStripe(Arena::FIRST_STRIPE);
    }

    /**
     * Runs $body in a forked child and returns its pid
     *
     * The child never returns into PHPUnit: it exits with the code $body produced, so the
     * only thing the parent has to interpret is an integer.
     *
     * @param callable(): int $body
     */
    private function fork(callable $body): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed');

        if ($pid > 0) {
            return $pid;
        }

        $code = self::CHILD_EXCEPTION;

        try {
            $code = $body();
        } catch (\Throwable) {
            // Reported as CHILD_EXCEPTION: a child must never print into the parent's run
        }

        exit($code);
    }

    /**
     * Waits for one child and returns its exit code
     */
    private function await(int $pid): int
    {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status), "child {$pid} did not exit normally");

        return pcntl_wexitstatus($status);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair), 'cannot create a socket pair');

        return [$pair[0], $pair[1]];
    }
}
