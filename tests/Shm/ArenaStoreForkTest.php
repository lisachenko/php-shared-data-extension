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

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Stub\AppConfig;
use Lisachenko\SharedData\Stub\GraphNode;
use PHPUnit\Framework\TestCase;

/**
 * Arena-backed persistence, across real processes
 *
 * What separates these cases from the frozen-mode suites is that nothing here can be
 * explained by copy-on-write inheritance: a graph persisted by a CHILD after the fork
 * cannot reach its parent through inherited pages, so the parent reading it proves the
 * bytes really do live in one shared mapping. Addresses cross process boundaries as eight
 * raw bytes over a socket - the Never-Serialize Rule applied to the test suite itself.
 *
 * One arena and one module per process: the arena must exist before any fork, and module
 * globals[0] anchors exactly one arena for the lifetime of the worker (a second one is
 * refused, deliberately). Guard cases that need their own capacity limits therefore boot
 * their own module.
 */
class ArenaStoreForkTest extends TestCase
{
    private const int ARENA_SIZE = 32 << 20;

    private const int OK               = 0;
    private const int WRONG_SCALAR     = 11;
    private const int WRONG_STRING     = 12;
    private const int WRONG_ARRAY      = 13;
    private const int NOT_IN_ARENA     = 14;
    private const int CHILD_EXCEPTION  = 40;

    private static ?Arena $arena = null;

    private static ?PersistentStore $store = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('ext-pcntl is required to exercise fork-shared memory');
        }

        // Every class whose objects travel through the arena must be loaded BEFORE the
        // fork: a shared clone carries ONE zend_class_entry pointer for the whole family,
        // so the address has to mean the same thing in every process (in production that
        // is what opcache.preload is for). A class first autoloaded inside a child lands
        // at an address only that child can follow.
        $this->assertTrue(class_exists(AppConfig::class));
        $this->assertTrue(class_exists(GraphNode::class));
    }

    protected function tearDown(): void
    {
        self::$store?->detach();
    }

    /**
     * The one arena of this process, with the store anchored in it
     */
    private function store(): PersistentStore
    {
        self::$arena ??= Arena::create(self::ARENA_SIZE);
        self::$store ??= PersistentStore::bootShared(self::$arena);

        return self::$store;
    }

    private function arena(): Arena
    {
        $this->store();
        \assert(self::$arena !== null);

        return self::$arena;
    }

    private function makeConfig(string $env, int $bootCount): AppConfig
    {
        $config            = new AppConfig();
        $config->env       = $env;
        $config->bootCount = $bootCount;
        $config->label     = 'primary';
        $config->settings  = [
            'db'       => ['host' => 'localhost', 'port' => 5432],
            'features' => ['alpha', 'beta'],
        ];

        return $config;
    }

    public function testPersistedGraphLivesInsideTheArena(): void
    {
        $store = $this->store();
        $arena = $this->arena();

        $before = $arena->watermark();
        $store->persist(AppConfig::class, $this->makeConfig('production', 1));
        $address = $store->addressOf(AppConfig::class);

        $this->assertNotNull($address);
        $this->assertTrue($arena->contains($address, 64), 'the persisted clone is not arena memory');
        $this->assertGreaterThan($before, $arena->watermark(), 'persisting did not consume arena bytes');
    }

    public function testTwoChildrenReadTheGraphPersistedBeforeTheFork(): void
    {
        $store = $this->store();
        $arena = $this->arena();

        $store->persist(AppConfig::class, $this->makeConfig('production', 7));
        $address = $store->addressOf(AppConfig::class);
        $this->assertNotNull($address);

        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $children[] = $this->fork(static function () use ($arena, $address): int {
                $childStore = PersistentStore::bootShared($arena);
                $config     = $childStore->get(AppConfig::class);

                if (!$config instanceof AppConfig || $config->bootCount !== 7) {
                    return self::WRONG_SCALAR;
                }
                if ($config->env !== 'production' || $config->label !== 'primary') {
                    return self::WRONG_STRING;
                }
                if ($config->settings['db']['port'] !== 5432 || $config->settings['features'][1] !== 'beta') {
                    return self::WRONG_ARRAY;
                }

                // ... and it is the very same object, at the very same address
                return $childStore->addressOf(AppConfig::class) === $address ? self::OK : self::NOT_IN_ARENA;
            });
        }

        foreach ($children as $pid) {
            $this->assertSame(self::OK, $this->await($pid), 'a child disagreed about the shared graph');
        }
    }

    public function testGraphPersistedByAChildIsAttachedByTheParentAndASiblingThroughItsAddress(): void
    {
        $store = $this->store();
        $arena = $this->arena();

        // Make sure the parent has an attached state BEFORE the child persists, so the
        // object the child creates is genuinely new to it
        $store->persist(AppConfig::class, $this->makeConfig('production', 1));

        [$parentEnd, $childEnd] = $this->socketPair();

        $writer = $this->fork(static function () use ($arena, $childEnd): int {
            $childStore = PersistentStore::bootShared($arena);

            $node          = new GraphNode();
            $node->name    = 'minted-after-the-fork';
            $node->counter = 42;

            $childStore->persist(GraphNode::class, $node);
            $address = $childStore->addressOf(GraphNode::class);
            \assert($address !== null);

            // The only thing that crosses: eight bytes of address
            socket_write($childEnd, pack('P', $address), 8);

            return self::OK;
        });
        socket_close($childEnd);

        $payload = (string) socket_read($parentEnd, 8, PHP_BINARY_READ);
        socket_close($parentEnd);
        $this->assertSame(self::OK, $this->await($writer));
        $this->assertSame(8, \strlen($payload), 'the child did not report the address of its graph');

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $payload);
        $address  = $unpacked[1];

        // Copy-on-write cannot explain this: the object was created after the fork
        $this->assertTrue($arena->contains($address, 64));

        $attached = $store->attachObject($address);
        $this->assertInstanceOf(GraphNode::class, $attached);
        $this->assertSame('minted-after-the-fork', $attached->name);
        $this->assertSame(42, $attached->counter);

        // A sibling forked afterwards attaches the same eight bytes
        $sibling = $this->fork(static function () use ($arena, $address): int {
            $siblingStore = PersistentStore::bootShared($arena);
            $node         = $siblingStore->attachObject($address);

            if (!$node instanceof GraphNode || $node->counter !== 42) {
                return self::WRONG_SCALAR;
            }

            return $node->name === 'minted-after-the-fork' ? self::OK : self::WRONG_STRING;
        });
        $this->assertSame(self::OK, $this->await($sibling), 'a sibling could not attach the address');
    }

    public function testGraphsKeepTheirRegistryTablesInsideTheArena(): void
    {
        $store = $this->store();
        $arena = $this->arena();

        $store->persist(AppConfig::class, $this->makeConfig('production', 3));

        // The three registry tables are published under their names, and every one of them
        // sits in the arena - the roots directory is all a forked child has to find them by
        foreach ([
            ArenaRegistryLayout::ROOT_TABLE,
            ArenaRegistryLayout::ROOT_ENTRIES,
            ArenaRegistryLayout::ROOT_OBJECTS,
        ] as $name) {
            $address = $arena->findRoot($name);
            $this->assertNotNull($address, "the registry did not publish {$name}");
            $this->assertTrue($arena->contains($address, 8), "{$name} does not live in the arena");
        }
    }

    public function testArenaExhaustionDuringPersistIsATypedFailure(): void
    {
        // Room for the registry tables, nowhere near enough for a stream of graphs: the
        // arena is bump-allocated, so re-persisting the same key consumes it steadily
        $store = $this->isolatedStore('shared_arena_tiny', 256 * 1024, new ArenaRegistryLayout(8, 64));

        $this->expectException(ArenaException::class);
        $this->expectExceptionMessageMatches('/Shared arena exhausted/');

        for ($index = 0; $index < 4096; $index++) {
            $store->persist(AppConfig::class, $this->makeConfig("env-{$index}", $index));
        }
    }

    public function testWatermarkIsVisibleToEveryProcessAndCountsWhatChildrenPersist(): void
    {
        $store = $this->store();
        $arena = $this->arena();

        $store->persist(AppConfig::class, $this->makeConfig('production', 1));
        $before = $arena->watermark();

        $pid = $this->fork(static function () use ($arena): int {
            $childStore = PersistentStore::bootShared($arena);
            $node       = new GraphNode();
            $node->name = 'child-node';

            $childStore->persist(GraphNode::class, $node);

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($pid));

        // The cursor lives in the arena: the parent sees the child's consumption
        $this->assertGreaterThan($before, $arena->watermark());
        $this->assertSame($arena->size() - Arena::HEADER_SIZE - $arena->watermark(), $arena->remaining());
    }

    /**
     * Boots a store on its own arena and its own persistent module
     */
    private function isolatedStore(string $module, int $size, ArenaRegistryLayout $layout): PersistentStore
    {
        return PersistentStore::bootShared(Arena::create($size), $layout, $module);
    }

    /**
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
