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

use Lisachenko\SharedData\PersistedObject;
use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Reclaimer;
use Lisachenko\SharedData\Stub\GraphNode;
use Lisachenko\SharedData\Stub\MutableCounter;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;

/**
 * Shared MUTABLE objects, exercised by real processes
 *
 * These are the claims of the validation sweep promoted to tests, so they are re-checked on
 * both minors on every change instead of resting on a log file:
 *
 *  - **S12** - one process writes, another reads, and the values are there. Two slots written
 *    in one critical section are never observed half-applied by a reader taking the same
 *    stripe lock;
 *  - **S16** - a string slot is a single aligned pointer, so a reader that skips the lock
 *    entirely still sees a complete string, old or new, and never a torn pointer;
 *  - **S14** - `handle` and `properties` are per-process. Two children attach the same objects
 *    without clobbering each other, the shared struct keeps a sentinel handle rather than
 *    anybody's real one, and a child that makes the engine cache a property bag inside a
 *    shared object leaves nothing behind for its sibling to dereference.
 *
 * Plus the lifecycle rules that only mean anything across processes: shared state is not
 * rolled back at request end, a child cannot free arena memory, and a worker that simply
 * exits without detaching does so cleanly.
 */
class MutableSharedForkTest extends TestCase
{
    private const int ARENA_SIZE = 32 << 20;

    private const string MODULE = 'shared_mutable';

    /**
     * Locked write/read rounds each child runs; the sweep's numbers were of this order and
     * the whole point is that a rare interleaving has time to happen
     */
    private const int ITERATIONS = 100_000;

    /**
     * The only string values the writer ever publishes: an unlocked reader must see one of
     * them, never a mixture and never a pointer into nowhere
     */
    private const array LABELS = ['initial', 'alpha-label', 'beta-label', 'gamma-label'];

    private const int OK               = 0;
    private const int INCONSISTENT     = 11;
    private const int TORN_STRING      = 12;
    private const int NO_PROGRESS      = 13;
    private const int WRONG_HANDLE     = 14;
    private const int WRONG_SENTINEL   = 15;
    private const int FOREIGN_POINTER  = 16;
    private const int WRONG_VALUE      = 17;
    private const int NOT_REFUSED      = 18;
    private const int CHILD_EXCEPTION  = 40;

    private static ?Arena $arena = null;

    private static ?PersistentStore $store = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('ext-pcntl is required to exercise fork-shared memory');
        }

        // Loaded before any fork: a shared clone carries one class entry for the family
        $this->assertTrue(class_exists(MutableCounter::class));
        $this->assertTrue(class_exists(GraphNode::class));
    }

    protected function tearDown(): void
    {
        self::$store?->detach();
    }

    private function store(): PersistentStore
    {
        self::$arena ??= Arena::create(self::ARENA_SIZE);
        self::$store ??= PersistentStore::bootShared(self::$arena, null, self::MODULE);

        return self::$store;
    }

    private function arena(): Arena
    {
        $this->store();
        \assert(self::$arena !== null);

        return self::$arena;
    }

    /**
     * Re-persists the mutable graph from scratch and returns the addresses of its two objects
     *
     * A storage key names a class, so the second object joins the graph as the peer of the
     * first rather than under a key of its own - which is also the more honest shape: it makes
     * every case work on a graph with an internal reference, exactly like a real one.
     *
     * @return array{0: int, 1: int} root address, peer address
     */
    private function persistCounters(): array
    {
        $store = $this->store();

        $root       = new MutableCounter();
        $root->peer = new MutableCounter();

        $shared = $store->persist(MutableCounter::class, $root, mutable: true);
        \assert($shared->peer !== null);

        return [$store->sharedIdOf($shared), $store->sharedIdOf($shared->peer)];
    }

    public function testTwoChildrenWriteAndReadTheSameObjectUnderItsStripeLock(): void
    {
        $arena   = $this->arena();
        $store   = $this->store();
        [$address] = $this->persistCounters();

        [$parentEnd, $childEnd] = $this->socketPair();

        $writer = $this->fork(static function () use ($arena, $address): int {
            $handle = PersistentStore::bootShared($arena, null, self::MODULE)->mutableHandle($address);

            for ($index = 1; $index <= self::ITERATIONS; $index++) {
                // Both slots in ONE critical section: that is what makes "counter === mirror"
                // an invariant a reader can rely on rather than a coincidence
                $handle->writeScalars(['counter' => $index, 'mirror' => $index]);

                if ($index % 1000 === 0) {
                    $handle->writeString('label', self::LABELS[1 + intdiv($index, 1000) % 3]);
                }
            }

            return self::OK;
        });

        $reader = $this->fork(static function () use ($arena, $address, $childEnd): int {
            $store    = PersistentStore::bootShared($arena, null, self::MODULE);
            $handle   = $store->mutableHandle($address);
            $instance = $store->attachObject($address);
            \assert($instance instanceof MutableCounter);

            $reads    = 0;
            $highest  = 0;
            $deadline = microtime(true) + 60.0;

            while (microtime(true) < $deadline) {
                $values = $handle->readScalars(['counter', 'mirror']);
                $reads++;

                if ($values['counter'] !== $values['mirror']) {
                    return self::INCONSISTENT;
                }
                // The unlocked half (correction #2): one aligned pointer read of a slot whose
                // type never changes gives a whole string, older or newer, but never a mixture
                if (!\in_array($instance->label, self::LABELS, true)) {
                    return self::TORN_STRING;
                }
                \assert(\is_int($values['counter']));
                $highest = max($highest, $values['counter']);

                if ($highest >= self::ITERATIONS) {
                    break;
                }
            }
            socket_write($childEnd, pack('PP', $reads, $highest), 16);

            return $highest > 0 ? self::OK : self::NO_PROGRESS;
        });
        socket_close($childEnd);

        $report = (string) socket_read($parentEnd, 16, PHP_BINARY_READ);
        socket_close($parentEnd);

        $this->assertSame(self::OK, $this->await($writer), 'the writing child failed');
        $this->assertSame(self::OK, $this->await($reader), 'the reading child saw an inconsistent object');
        $this->assertSame(16, \strlen($report), 'the reader did not report its counts');

        /** @var array{1: int, 2: int} $counts */
        $counts = unpack('P2', $report);
        $this->assertGreaterThan(1000, $counts[1], 'the reader barely ran, so it proves little');
        $this->assertGreaterThan(0, $counts[2], 'the reader never observed a value written by its sibling');

        // And the parent, which did neither, sees where the writer stopped
        $handle = $store->mutableHandle($address);
        $this->assertSame(self::ITERATIONS, $handle->readScalar('counter'));
        $this->assertSame(self::ITERATIONS, $handle->readScalar('mirror'));
        $this->assertContains($handle->readString('label'), self::LABELS);
    }

    public function testAChildWritesAStringAndAReferenceThatEveryOtherProcessCanFollow(): void
    {
        $arena  = $this->arena();
        $store  = $this->store();
        [$first, $second] = $this->persistCounters();

        $child = $this->fork(static function () use ($arena, $first, $second): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);
            $handle     = $childStore->mutableHandle($first);

            $handle->writeString('note', 'written-by-the-child');

            // Clearing and re-pointing: both halves of a reference write, and the target may
            // only ever be another object of this very arena
            $handle->writeReference('peer', null);
            $handle->writeReference('peer', $childStore->attachObject($second));
            $childStore->mutableHandle($second)->writeScalar('counter', 4242);

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($child));

        // The bytes were interned in the arena by another process, and the reference is an
        // address rather than anything that had to be encoded
        $handle = $store->mutableHandle($first);
        $this->assertSame('written-by-the-child', $handle->readString('note'));

        $peer = $handle->readReference('peer');
        $this->assertInstanceOf(MutableCounter::class, $peer);
        $this->assertSame($second, $store->sharedIdOf($peer), 'the reference does not point at the shared peer');
        $this->assertSame(4242, $store->mutableHandle($second)->readScalar('counter'));

        // ... and the ordinary PHP read agrees with the synchronized one
        $instance = $store->attachObject($first);
        \assert($instance instanceof MutableCounter);
        $this->assertSame('written-by-the-child', $instance->note);
        $this->assertSame(4242, $instance->peer?->counter);
    }

    public function testConcurrentAttachKeepsEveryHandlePerProcessAndTheSharedFieldASentinel(): void
    {
        $arena   = $this->arena();
        $store   = $this->store();
        [$counter] = $this->persistCounters();

        $store->persist(GraphNode::class, new GraphNode('attach-node'), mutable: true);
        $node = $store->addressOf(GraphNode::class);
        $this->assertNotNull($node);

        // The two children overlap deliberately: the first one STAYS attached until the second
        // has finished its checks, so both hold the same objects registered at the same time.
        // It also keeps the second child clear of the one moment the shared handle field is
        // not the sentinel - a detaching process puts its own handle back for the length of
        // the store call that recycles its slot (see PersistentStore::releaseHandle())
        [$firstEnd, $secondEnd] = $this->socketPair();
        [$reportEnd, $writeEnd] = $this->socketPair();

        $lingering = $this->fork(static function () use ($arena, $counter, $node, $firstEnd, $writeEnd): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);

            $childStore->attachObject($counter);
            $childStore->attachObject($node);

            $handle = $childStore->processHandleOf($counter);
            socket_write($writeEnd, pack('P', $handle ?? 0), 8);

            // Stays attached until its sibling says it is done
            socket_read($firstEnd, 1, PHP_BINARY_READ);

            return $handle === null ? self::WRONG_HANDLE : self::OK;
        });
        socket_close($writeEnd);

        $overlapping = $this->fork(static function () use ($arena, $counter, $node, $secondEnd): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);

            $first  = $childStore->attachObject($counter);
            $second = $childStore->attachObject($node);

            $firstHandle  = $childStore->processHandleOf($counter);
            $secondHandle = $childStore->processHandleOf($node);

            $code = self::OK;

            // Its OWN handles: two objects of one process never share a store slot
            if ($firstHandle === null || $secondHandle === null || $firstHandle === $secondHandle) {
                $code = self::WRONG_HANDLE;
            } elseif (
                // ... while the shared struct carries a number no object store can produce,
                // which is exactly why spl_object_id() cannot be an identity here
                spl_object_id($first) !== PersistentStore::SHARED_HANDLE_SENTINEL
                || spl_object_id($second) !== PersistentStore::SHARED_HANDLE_SENTINEL
            ) {
                $code = self::WRONG_SENTINEL;
            } elseif ($childStore->sharedIdOf($first) === $childStore->sharedIdOf($second)) {
                $code = self::WRONG_VALUE;
            }

            socket_write($secondEnd, 'x', 1);

            return $code;
        });

        $reported = (string) socket_read($reportEnd, 8, PHP_BINARY_READ);
        socket_close($reportEnd);
        socket_close($firstEnd);
        socket_close($secondEnd);

        $this->assertSame(self::OK, $this->await($overlapping), 'the overlapping child disagreed about its handles');
        $this->assertSame(self::OK, $this->await($lingering), 'the lingering child never got a handle');

        $this->assertSame(8, \strlen($reported), 'the lingering child did not report its handle');
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $reported);
        $this->assertGreaterThan(0, $unpacked[1], 'a child must hold a real object-store handle of its own');

        // Nothing a child did leaked into the struct every process reads
        $instance = $store->attachObject($counter);
        $this->assertSame(PersistentStore::SHARED_HANDLE_SENTINEL, spl_object_id($instance));
        $this->assertSame($counter, $store->sharedIdOf($instance));
        $this->assertNotNull($store->processHandleOf($counter));
        $this->assertNotSame(
            PersistentStore::SHARED_HANDLE_SENTINEL,
            $store->processHandleOf($counter),
            'the side table must hold the real handle, not the sentinel',
        );
    }

    public function testAChildInspectingASharedObjectLeavesNoHeapPointerForItsSibling(): void
    {
        $arena   = $this->arena();
        $store   = $this->store();
        [$address] = $this->persistCounters();

        [$parentEnd, $childEnd] = $this->socketPair();
        [$siblingEnd, $signalEnd] = $this->socketPair();

        $inspector = $this->fork(static function () use ($arena, $address, $childEnd, $signalEnd): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);
            $instance   = $childStore->attachObject($address);

            // The engine caches the rebuilt property bag inside the object - a pointer into
            // THIS child's request heap, deposited in memory the whole family reads
            get_object_vars($instance);
            if ($childStore->dynamicPropertiesAddressOf($address) === 0) {
                // Nothing was cached, so the rest of the case would prove nothing
                return self::WRONG_VALUE;
            }
            $childStore->scrubProperties($address);
            if ($childStore->dynamicPropertiesAddressOf($address) !== 0) {
                return self::FOREIGN_POINTER;
            }

            // The bracketed form does the same for a var_dump()
            $childStore->inspect($address, static function (object $shared): void {
                ob_start();
                var_dump($shared);
                ob_end_clean();
            });
            if ($childStore->dynamicPropertiesAddressOf($address) !== 0) {
                return self::FOREIGN_POINTER;
            }

            socket_write($childEnd, 'x', 1);
            socket_write($signalEnd, 'x', 1);

            return self::OK;
        });
        socket_close($childEnd);
        socket_close($signalEnd);

        $sibling = $this->fork(static function () use ($arena, $address, $siblingEnd): int {
            // Runs strictly AFTER the inspector: this is the moment S14 crashed
            socket_read($siblingEnd, 1, PHP_BINARY_READ);

            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);
            if ($childStore->dynamicPropertiesAddressOf($address) !== 0) {
                return self::FOREIGN_POINTER;
            }
            $instance = $childStore->attachObject($address);
            \assert($instance instanceof MutableCounter);

            if ($instance->label !== 'initial' || $instance->counter !== 0) {
                return self::WRONG_VALUE;
            }

            return self::OK;
        });

        $this->assertSame('x', (string) socket_read($parentEnd, 1, PHP_BINARY_READ));
        socket_close($parentEnd);
        socket_close($siblingEnd);

        $this->assertSame(self::OK, $this->await($inspector), 'the inspecting child failed');
        $this->assertSame(self::OK, $this->await($sibling), 'a sibling found a foreign pointer inside the object');
        $this->assertSame(0, $store->dynamicPropertiesAddressOf($address));
    }

    public function testSharedMutableStateIsNeverRolledBackWhileAFrozenGraphStillIs(): void
    {
        $store   = $this->store();
        [$address] = $this->persistCounters();

        $store->mutableHandle($address)->writeScalars(['counter' => 7, 'ratio' => 1.5]);
        $store->mutableHandle($address)->writeString('label', 'beta-label');

        $store->detach();
        $store->attach();

        $handle = $store->mutableHandle($address);
        $this->assertSame(7, $handle->readScalar('counter'), 'a shared mutable graph was rolled back');
        $this->assertSame(1.5, $handle->readScalar('ratio'));
        $this->assertSame('beta-label', $handle->readString('label'));

        // The frozen default, in the very same arena, still behaves exactly as it always has
        $store->persist(GraphNode::class, new GraphNode('frozen'), mutable: false);
        $frozen = $store->addressOf(GraphNode::class);
        $this->assertNotNull($frozen);

        $instance = $store->attachObject($frozen);
        \assert($instance instanceof GraphNode);
        $instance->counter = 99;

        $store->detach();
        $store->attach();

        $restored = $store->attachObject($frozen);
        \assert($restored instanceof GraphNode);
        $this->assertSame(0, $restored->counter, 'a frozen graph must still be restored from its snapshot');
    }

    public function testADirectWriteIsVisibleToSiblingsButItsHeapStringIsRepairedAtDetach(): void
    {
        $arena   = $this->arena();
        $store   = $this->store();
        [$address] = $this->persistCounters();

        $store->mutableHandle($address)->writeString('label', 'gamma-label');

        // Documented behaviour: a plain property write reaches shared memory, and for a SCALAR
        // that is all it is - visible everywhere, and unsynchronized
        $child = $this->fork(static function () use ($arena, $address): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);
            $instance   = $childStore->attachObject($address);
            \assert($instance instanceof MutableCounter);

            $instance->counter = 31337;

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($child));
        $this->assertSame(31337, $store->mutableHandle($address)->readScalar('counter'));

        // A direct STRING write is the dangerous half: the engine stores a request-heap
        // zend_string pointer inside shared memory, which no sibling may follow
        $instance = $store->attachObject($address);
        \assert($instance instanceof MutableCounter);
        $instance->label = 'written-without-the-api';
        $this->assertSame('written-without-the-api', $instance->label);

        $before = $store->repairedSlotCount();
        $store->detach();
        $this->assertGreaterThan($before, $store->repairedSlotCount(), 'the foreign pointer was left in the arena');

        $store->attach();
        $this->assertSame(
            'initial',
            $store->mutableHandle($address)->readString('label'),
            'the repaired slot must hold the persisted image, the only value known to be in the arena',
        );
        $this->assertSame(31337, $store->mutableHandle($address)->readScalar('counter'), 'scalars are not rolled back');
    }

    public function testAChildIsRefusedWhenItTriesToFreeArenaMemory(): void
    {
        $arena   = $this->arena();
        $store   = $this->store();
        [$address] = $this->persistCounters();

        $child = $this->fork(static function () use ($arena, $address): int {
            PersistentStore::bootShared($arena, null, self::MODULE);

            if (!Reclaimer::isProtected($address)) {
                return self::WRONG_VALUE;
            }

            // A record over real arena blocks: exactly what a reclamation path would hold
            $object = new PersistedObject(
                $address,
                Core::pointerAtAddress('zend_object *', $address),
                Core::pointerAtAddress('char *', $address),
                MutableCounter::class,
                'signature',
            );

            try {
                Reclaimer::reclaimObject($object);
            } catch (ArenaException) {
                return self::OK;
            }

            return self::NOT_REFUSED;
        });
        $this->assertSame(self::OK, $this->await($child), 'a child was allowed to free arena memory');

        // The refusal happens before anything is released, so the object is untouched
        $instance = $store->attachObject($address);
        \assert($instance instanceof MutableCounter);
        $this->assertSame('initial', $instance->label);

        // ... and dropping a shared graph frees nothing either, whoever asks
        $watermark = $arena->watermark();
        $this->assertTrue($store->drop(MutableCounter::class));
        $this->assertSame($watermark, $arena->watermark(), 'dropping a shared entry must not move the cursor');
    }

    public function testAWorkerThatExitsWithoutDetachingStillExitsCleanly(): void
    {
        $arena   = $this->arena();
        [$address] = $this->persistCounters();

        $pid = $this->fork(static function () use ($arena, $address): int {
            $childStore = PersistentStore::bootShared($arena, null, self::MODULE);
            $handle     = $childStore->mutableHandle($address);

            $handle->writeScalars(['counter' => 5, 'flag' => true]);
            $handle->writeString('label', 'alpha-label');

            // Deliberately kept alive over the exit: request shutdown releases these AFTER
            // the shutdown functions have run, which is where a teardown-ordering bug shows
            $GLOBALS['shared_teardown_probe'] = $childStore->attachObject($address);

            return self::OK;
        });

        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->assertFalse(pcntl_wifsignaled($status), 'the worker died from a signal during shutdown');
        $this->assertTrue(pcntl_wifexited($status), 'the worker did not exit normally');
        $this->assertSame(self::OK, pcntl_wexitstatus($status));
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
