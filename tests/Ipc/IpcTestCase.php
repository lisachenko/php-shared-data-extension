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
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use Lisachenko\SharedData\Stub\AppConfig;
use Lisachenko\SharedData\Stub\GraphNode;
use PHPUnit\Framework\TestCase;

/**
 * The harness every IPC case shares: one arena, one store, one notification plane per PROCESS
 *
 * Three constraints shape it, and they are all properties of the thing being tested rather
 * than of PHPUnit:
 *
 *  - **one arena per persistent module.** Module globals anchor exactly one arena for the
 *    lifetime of a worker, so every case here uses the same mapping (and its own module name,
 *    kept apart from the arena-store suite that runs in the same process).
 *  - **the notification plane must exist before any fork.** Its socket pairs are inherited,
 *    never handed over, so it is created once here and reused by every case - exactly as a
 *    supervisor would create it before spawning its pool.
 *  - **classes travelling through the arena must be loaded before the fork**, since a shared
 *    clone carries one class-entry pointer for the whole family.
 *
 * Children answer by EXIT CODE and never touch the result printer; the parent is the only
 * process that asserts. Values that have to travel do so as raw bytes over a socket pair or,
 * better, as an address in the arena - never as a serialized PHP value.
 */
abstract class IpcTestCase extends TestCase
{
    protected const int ARENA_SIZE = 48 << 20;

    protected const int WAKE_SLOTS = 24;

    /**
     * Persistent module of this suite, so the arena-store suite in the same process keeps its own
     */
    protected const string MODULE = 'shared_arena_ipc';

    protected const int OK              = 0;
    protected const int WRONG_VALUE     = 11;
    protected const int WRONG_ORDER     = 12;
    protected const int WRONG_STATE     = 13;
    protected const int TIMED_OUT       = 14;
    protected const int CHILD_EXCEPTION = 40;

    private static ?Arena $arena = null;

    private static ?PersistentStore $store = null;

    private static ?ArenaAllocator $allocator = null;

    private static ?ValueCodec $codec = null;

    private static ?WakeRegistry $wake = null;

    private static ?GraphNode $sharedNode = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('ext-pcntl is required to exercise fork-shared IPC');
        }

        // Loaded BEFORE any fork: a shared clone carries one zend_class_entry pointer for the
        // whole family, and a class first autoloaded inside a child lands at an address only
        // that child can follow
        $this->assertTrue(class_exists(GraphNode::class));
        $this->assertTrue(class_exists(AppConfig::class));
        $this->assertTrue(class_exists(SharedError::class));
    }

    protected function arena(): Arena
    {
        return self::$arena ??= Arena::create(self::ARENA_SIZE);
    }

    protected function store(): PersistentStore
    {
        return self::$store ??= PersistentStore::bootShared($this->arena(), null, self::MODULE);
    }

    protected function allocator(): ArenaAllocator
    {
        return self::$allocator ??= new ArenaAllocator($this->arena());
    }

    protected function codec(): ValueCodec
    {
        return self::$codec ??= new ValueCodec($this->allocator(), $this->store());
    }

    /**
     * The one notification plane of this process family, created before any case forks
     */
    protected function wake(): WakeRegistry
    {
        return self::$wake ??= WakeRegistry::create($this->arena(), self::WAKE_SLOTS, null);
    }

    /**
     * The one shared object of this process, persisted once and then only ever shared
     *
     * Persisting is an upsert keyed by class, and releasing the superseded generation is
     * refused while the request can still reach it - which is the correct behaviour and the
     * wrong thing to fight in every case. Real code persists a graph once and hands its
     * ADDRESS around, so the suite does the same.
     */
    protected function sharedNode(): GraphNode
    {
        if (self::$sharedNode === null) {
            $node          = new GraphNode();
            $node->name    = 'shared-by-address';
            $node->counter = 42;

            self::$sharedNode = $this->store()->persist(GraphNode::class, $node);
        }

        return self::$sharedNode;
    }

    protected function channel(int $capacity, int $waiters = SharedChannel::DEFAULT_WAITERS): SharedChannel
    {
        return SharedChannel::create($this->allocator(), $this->codec(), $this->wake(), $capacity, $waiters);
    }

    /**
     * Runs $body in a forked child and returns its pid
     *
     * @param callable(): int $body
     */
    protected function fork(callable $body): int
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
    protected function await(int $pid): int
    {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status), "child {$pid} did not exit normally");

        return pcntl_wexitstatus($status);
    }

    /**
     * @param list<int> $children
     */
    protected function awaitAll(array $children, string $message = 'a child disagreed'): void
    {
        foreach ($children as $pid) {
            $this->assertSame(self::OK, $this->await($pid), $message);
        }
    }

    /**
     * Spins until an arena word reaches $expected, so the parent can rendezvous with a child
     *
     * @return bool Whether the word got there before the timeout
     */
    protected function awaitWord(int $address, int $expected, float $timeout = 5.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while ($this->arena()->readWord($address) !== $expected) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(1_000);
        }

        return true;
    }
}
