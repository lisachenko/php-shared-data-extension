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

use Lisachenko\SharedData\Stub\GraphNode;

/**
 * The mutable containers and the synchronization primitives, exercised by real processes
 *
 * The interesting cases are the ones a single process cannot fake: four children adding to
 * one counter, a worker killed inside a critical section, a wait group whose units of work
 * finish somewhere else entirely.
 */
class SharedStructuresForkTest extends IpcTestCase
{
    public function testFourWorkersFillOneSharedArrayAndTheParentReadsAllOfIt(): void
    {
        $array = SharedArray::create($this->allocator(), $this->codec(), 8);

        $children = [];
        for ($index = 0; $index < 4; $index++) {
            $children[] = $this->fork(static function () use ($array, $index): int {
                $array[$index]     = "worker-{$index}";
                $array[$index + 4] = $index * 100;

                return self::OK;
            });
        }
        $this->awaitAll($children);

        for ($index = 0; $index < 4; $index++) {
            $this->assertSame("worker-{$index}", $array[$index], 'a worker\'s string did not reach the parent');
            $this->assertSame($index * 100, $array[$index + 4]);
        }
        $this->assertCount(8, $array);
    }

    public function testASharedArrayCarriesEveryTagIncludingObjectsAndNestedArrays(): void
    {
        $inner    = SharedArray::create($this->allocator(), $this->codec(), 2);
        $inner[0] = 'nested';

        $shared = $this->sharedNode();

        $array    = SharedArray::create($this->allocator(), $this->codec(), 6);
        $array[0] = null;
        $array[1] = true;
        $array[2] = -17;
        $array[3] = 0.5;
        $array[4] = $shared;
        $array[5] = $inner;

        $pid = $this->fork(static function () use ($array, $shared): int {
            if ($array[0] !== null || $array[1] !== true || $array[2] !== -17 || $array[3] !== 0.5) {
                return self::WRONG_VALUE;
            }
            $object = $array[4];
            if (!$object instanceof GraphNode || $object->name !== 'shared-by-address') {
                return self::WRONG_VALUE;
            }
            // Zero-copy all the way down: the same object, at the same address
            if ($object !== $shared) {
                return self::WRONG_STATE;
            }
            $nested = $array[5];

            return $nested instanceof SharedArray && $nested[0] === 'nested' ? self::OK : self::WRONG_VALUE;
        });

        $this->assertSame(self::OK, $this->await($pid), 'a child disagreed about the shared array');
    }

    public function testAnIndexOutsideAFixedCapacityArrayIsATypedFailure(): void
    {
        $array = SharedArray::create($this->allocator(), $this->codec(), 4);

        $this->assertTrue(isset($array[3]));
        $this->assertFalse(isset($array[4]));

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/cannot grow/');

        $array[4] = 'past the end';
    }

    public function testAppendingToASharedArrayIsRefusedBecauseItCannotGrow(): void
    {
        $array = SharedArray::create($this->allocator(), $this->codec(), 2);

        $this->expectException(IpcException::class);

        $array[] = 'append';
    }

    public function testASharedMutexExcludesTwoProcessesFromOneCriticalSection(): void
    {
        $arena   = $this->arena();
        $mutex   = SharedMutex::create($arena);
        $witness = $arena->allocate(8);
        $arena->writeWord($witness, 0);

        $holder = $this->fork(static function () use ($arena, $mutex, $witness): int {
            if (!$mutex->lock(5.0)) {
                return self::TIMED_OUT;
            }
            // Inside the critical section: announce it, stay a while, then leave
            $arena->writeWord($witness, 1);
            usleep(300_000);
            $arena->writeWord($witness, 0);
            $mutex->unlock();

            return self::OK;
        });

        $this->assertTrue($this->awaitWord($witness, 1), 'the child never entered the critical section');
        $this->assertFalse($mutex->tryLock(), 'two processes were allowed into one critical section');

        $before = microtime(true);
        $this->assertTrue($mutex->lock(10.0), 'the parent never got the lock after the child released it');
        $this->assertSame(0, $arena->readWord($witness), 'the lock was granted while the child was still inside');
        $mutex->unlock();

        $this->assertGreaterThan(0.05, microtime(true) - $before, 'the parent did not actually wait for the child');
        $this->assertSame(self::OK, $this->await($holder));
    }

    public function testAMutexHeldByAKilledWorkerIsRecoveredRatherThanLostForever(): void
    {
        $arena  = $this->arena();
        $mutex  = SharedMutex::create($arena);
        $signal = $arena->allocate(8);
        $arena->writeWord($signal, 0);

        $victim = $this->fork(static function () use ($arena, $mutex, $signal): int {
            $mutex->lock(5.0);
            // Announce the lock is held, then die inside the critical section
            $arena->writeWord($signal, 1);
            sleep(30);

            return self::OK;
        });

        $this->assertTrue($this->awaitWord($signal, 1), 'the victim never took the lock');
        posix_kill($victim, SIGKILL);
        pcntl_waitpid($victim, $status);

        // ROBUST: the next locker is told the owner died (EOWNERDEAD) and makes it consistent
        // again, instead of blocking on a lock nobody will ever release
        $this->assertTrue($mutex->lock(10.0));
        $this->assertTrue($mutex->wasRecovered(), 'the died owner was not reported through the lock result');
        $mutex->unlock();

        // ... and the mutex is an ordinary working mutex afterwards
        $this->assertTrue($mutex->tryLock());
        $mutex->unlock();
    }

    public function testFourChildrenAddingToOneAtomicIntSumCorrectly(): void
    {
        $counter    = AtomicInt::create($this->arena(), 0);
        $perChild   = 250;
        $childCount = 4;

        $children = [];
        for ($index = 0; $index < $childCount; $index++) {
            $children[] = $this->fork(static function () use ($counter, $perChild): int {
                for ($step = 0; $step < $perChild; $step++) {
                    $counter->add(1);
                }

                return self::OK;
            });
        }
        $this->awaitAll($children);

        // A lost update would show up here as a number below the sum; the stripe mutex is
        // what makes read-modify-write safe without a CAS instruction
        $this->assertSame($childCount * $perChild, $counter->get());
    }

    public function testCompareAndSetLetsExactlyOneChildClaimAToken(): void
    {
        $token  = AtomicInt::create($this->arena(), 0);
        $claims = AtomicInt::create($this->arena(), 0);

        $children = [];
        for ($index = 1; $index <= 4; $index++) {
            $children[] = $this->fork(static function () use ($token, $claims, $index): int {
                if ($token->compareAndSet(0, $index)) {
                    $claims->add(1);
                }

                return self::OK;
            });
        }
        $this->awaitAll($children);

        $this->assertSame(1, $claims->get(), 'more than one child won the same compare-and-set');
        $this->assertGreaterThan(0, $token->get());
    }

    public function testAWaitGroupParksTheParentUntilEveryChildIsDone(): void
    {
        $group = SharedWaitGroup::create($this->arena(), $this->wake());
        $group->add(4);

        $children = [];
        for ($index = 0; $index < 4; $index++) {
            $children[] = $this->fork(static function () use ($group, $index): int {
                usleep(50_000 * ($index + 1));
                $group->done();

                return self::OK;
            });
        }

        $before = microtime(true);
        $this->assertTrue($group->wait(10.0), 'the wait group never reached zero');
        $elapsed = microtime(true) - $before;

        $this->assertSame(0, $group->count());
        $this->assertGreaterThan(0.15, $elapsed, 'the wait returned before the slowest child was done');
        $this->awaitAll($children);
    }

    public function testAWaitGroupCounterGoingNegativeIsAHardError(): void
    {
        $group = SharedWaitGroup::create($this->arena(), $this->wake());
        $group->add(1);
        $group->done();

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/went negative/');

        $group->done();
    }
}
