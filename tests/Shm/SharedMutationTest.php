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
use Lisachenko\SharedData\SharedMutationException;
use Lisachenko\SharedData\Stub\GraphNode;
use Lisachenko\SharedData\Stub\MutableCounter;
use PHPUnit\Framework\TestCase;
use ZEngine\Core;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;

/**
 * The mutation contract of a shared graph, and what it refuses
 *
 * Everything here is a single process, because none of it is about concurrency: it is about
 * what may be written into shared memory at all. The rule behind every refusal is the same -
 * a slot of a shared object may hold a scalar, an arena-interned string or a pointer to
 * another object of the same arena, and anything else is a pointer some other process cannot
 * follow. Refusals therefore happen BEFORE a lock is taken and before a byte is written.
 */
class SharedMutationTest extends TestCase
{
    private const int ARENA_SIZE = 16 << 20;

    private const string MODULE        = 'shared_mutation';
    private const string FROZEN_MODULE = 'shared_mutation_frozen';

    private static ?Arena $arena = null;

    private static ?PersistentStore $store = null;

    private static ?PersistentStore $frozenStore = null;

    protected function tearDown(): void
    {
        self::$store?->detach();
        self::$frozenStore?->detach();
    }

    private function store(): PersistentStore
    {
        self::$arena ??= Arena::create(self::ARENA_SIZE);
        self::$store ??= PersistentStore::bootShared(self::$arena, null, self::MODULE);

        return self::$store;
    }

    /**
     * The default, malloc-backed store: the mode every refusal below is contrasted against
     */
    private function frozenStore(): PersistentStore
    {
        return self::$frozenStore ??= PersistentStore::boot(self::FROZEN_MODULE);
    }

    private function persistCounter(): MutableCounter
    {
        $counter = new MutableCounter();

        return $this->store()->persist(MutableCounter::class, $counter, mutable: true);
    }

    public function testAMutableGraphNeedsTheArena(): void
    {
        $this->expectException(SharedMutationException::class);
        $this->expectExceptionMessageMatches('/mutable graphs exist only in a fork-shared arena/');

        $this->frozenStore()->persist(GraphNode::class, new GraphNode('heap'), mutable: true);
    }

    public function testAFrozenGraphInTheArenaRefusesAWriteHandle(): void
    {
        $store  = $this->store();
        $frozen = $store->persist(GraphNode::class, new GraphNode('frozen'), mutable: false);

        $this->assertTrue($store->isShared());
        $this->assertFalse($store->isMutable($frozen));

        $this->expectException(SharedMutationException::class);
        $this->expectExceptionMessageMatches('/belongs to a FROZEN graph/');

        $store->mutableHandle($frozen);
    }

    public function testOneObjectCannotBelongToAFrozenAndAMutableGraphAtOnce(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();

        // A frozen graph reaching into the mutable one would roll its members back at request
        // end - undoing, without a word, whatever another process wrote through them
        $node         = new GraphNode('reaching');
        $node->shared = null;
        $holder       = new GraphNode('holder');
        $holder->services = ['counter' => $shared];

        $this->expectException(SharedMutationException::class);
        $this->expectExceptionMessageMatches('/One object cannot be both/');

        $store->persist(GraphNode::class, $holder, mutable: false);
    }

    public function testTheSealedArrayPropertyStaysImmutableAndItsSlotRefusesEveryWrite(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();
        $handle = $store->mutableHandle($shared);

        $this->assertSame(['frozen' => true], $handle->read('sealed'));
        $this->assertSame(['boxed' => 1], $handle->read('payload'));

        // A declared-array property is refused by the type check; an UNTYPED slot that happens
        // to hold an array is refused by the slot itself, which is the rule that matters:
        // a shared zend_array can never be replaced or grown, whatever the declaration says
        $this->expectException(SharedMutationException::class);
        $this->expectExceptionMessageMatches('/sealed shared array/');

        $handle->writeScalar('payload', 1);
    }

    public function testMutatingASealedArrayThroughPhpIsContainedRatherThanShared(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();

        // The engine separates the immutable array into a REQUEST array and stores that
        // pointer in the shared slot - which is precisely what must never survive the request
        $shared->sealed['added'] = true;
        $this->assertArrayHasKey('added', $shared->sealed);

        $before = $store->repairedSlotCount();
        $store->detach();
        $this->assertGreaterThan($before, $store->repairedSlotCount());

        $store->attach();
        $this->assertSame(
            ['frozen' => true],
            $store->mutableHandle($store->addressOf(MutableCounter::class) ?? 0)->read('sealed'),
        );
    }

    public function testDeclaredPropertyTypesAreEnforcedByTheWritePath(): void
    {
        $store  = $this->store();
        $handle = $store->mutableHandle($this->persistCounter());

        $handle->writeScalar('counter', 5);
        $handle->writeString('label', 'still-a-string');

        // int into a string property: the engine would have refused it, and the write path
        // stores the value directly, so it refuses it here instead
        try {
            $handle->writeScalar('label', 7);
            $this->fail('a typed property accepted a value of the wrong type');
        } catch (SharedMutationException $exception) {
            $this->assertStringContainsString('is declared string', $exception->getMessage());
        }

        try {
            $handle->writeString('counter', 'seven');
            $this->fail('an int property accepted a string');
        } catch (SharedMutationException $exception) {
            $this->assertStringContainsString('is declared int', $exception->getMessage());
        }

        // null into a non-nullable property is the same mistake
        $this->expectException(SharedMutationException::class);
        $handle->writeScalar('label', null);
    }

    public function testIntIsWidenedIntoAFloatPropertyExactlyAsTheEngineWould(): void
    {
        $handle = $this->store()->mutableHandle($this->persistCounter());

        $handle->writeScalar('ratio', 3);

        $this->assertSame(3.0, $handle->readScalar('ratio'));
    }

    public function testAReferenceMayOnlyPointAtAnotherObjectOfThisArena(): void
    {
        $store  = $this->store();
        $handle = $store->mutableHandle($this->persistCounter());

        try {
            $handle->writeReference('peer', new MutableCounter());
            $this->fail('a request-heap object was accepted as a shared reference');
        } catch (SharedMutationException $exception) {
            $this->assertStringContainsString('persist(', $exception->getMessage());
        }

        // The nullable half is legal, and so is pointing it back at a shared object
        $handle->writeReference('peer', null);
        $this->assertNull($handle->readReference('peer'));
    }

    public function testAnUnknownPropertyAndAMisreadSlotAreBothTypedFailures(): void
    {
        $handle = $this->store()->mutableHandle($this->persistCounter());

        try {
            $handle->writeScalar('noSuchProperty', 1);
            $this->fail('an undeclared property was accepted');
        } catch (SharedMutationException $exception) {
            $this->assertStringContainsString('no property $noSuchProperty', $exception->getMessage());
        }

        $handle->writeScalar('counter', 12);

        $this->expectException(SharedMutationException::class);
        $this->expectExceptionMessageMatches('/is not a string/');
        $handle->readString('counter');
    }

    public function testEveryScalarShapeSurvivesOneWriteAndOneRead(): void
    {
        $handle = $this->store()->mutableHandle($this->persistCounter());

        $handle->writeScalars([
            'counter' => -42,
            'mirror'  => -42,
            'ratio'   => 0.125,
            'flag'    => true,
        ]);
        $handle->writeString('note', 'a note');

        $this->assertSame(
            ['counter' => -42, 'mirror' => -42, 'ratio' => 0.125, 'flag' => true],
            $handle->readScalars(['counter', 'mirror', 'ratio', 'flag']),
        );
        $this->assertSame('a note', $handle->readString('note'));

        $handle->writeScalar('flag', false);
        $handle->writeScalar('note', null);

        $this->assertFalse($handle->readScalar('flag'));
        $this->assertNull($handle->readString('note'));
        $this->assertFalse($handle->wasLockRecovered(), 'no lock of this test was held by a dead owner');
    }

    public function testAMutableGraphKeepsThePinAndTheEngineFlagsOfAPersistentClone(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();

        // Lifting the seals of the mutation surface does not lift the pin: the clone must stay
        // invisible to refcounting and to the cycle collector exactly as a frozen one is
        $entry = new ObjectEntry($shared);
        $this->assertGreaterThanOrEqual(PersistentObjectFactory::PIN_BASELINE, $entry->getReferenceCount());
        $entry->release();

        $this->assertTrue($store->isMutable($shared));
        $this->assertSame($store->addressOf(MutableCounter::class), $store->sharedIdOf($shared));
    }

    public function testIdentityIsTheArenaAddressAndNothingElse(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();

        $this->assertSame(PersistentStore::SHARED_HANDLE_SENTINEL, spl_object_id($shared));
        $this->assertNotSame(0, $store->sharedIdOf($shared));

        // An ordinary request object has no shared identity at all
        try {
            $store->sharedIdOf(new MutableCounter());
            $this->fail('a request object was given a shared identity');
        } catch (SharedMutationException $exception) {
            $this->assertStringContainsString('not a shared object', $exception->getMessage());
        }
    }

    public function testDroppingASharedGraphIsAllowedWhileAnAliasIsHeldAndFreesNothing(): void
    {
        $store  = $this->store();
        $arena  = self::$arena;
        \assert($arena !== null);

        $shared = $this->persistCounter();
        $store->mutableHandle($shared)->writeScalar('counter', 3);

        $watermark = $arena->watermark();

        // The alias predicate is disabled for shared graphs: a refcount in the arena is
        // written by every process that ever copied the value, so it can neither prove nor
        // disprove that this request still holds one - and there is nothing to protect,
        // because dropping a shared entry frees no memory at all
        $this->assertTrue($store->drop(MutableCounter::class));
        $this->assertSame($watermark, $arena->watermark());
        $this->assertFalse($store->has(MutableCounter::class));

        // The alias is still readable: the bytes are simply still there
        $this->assertSame(3, $shared->counter);
    }

    public function testTheFrozenStoreStillRefusesToDropAGraphTheRequestCanReach(): void
    {
        $store = $this->frozenStore();
        $node  = $store->persist(GraphNode::class, new GraphNode('aliased'));

        $this->assertNotNull($node);

        // Byte-identical to the behaviour before shared mode existed
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/still holds a reference/');

        $store->drop(GraphNode::class);
    }

    public function testArenaBlocksAreRefusedByEveryFreePathOfThisProcess(): void
    {
        $store  = $this->store();
        $shared = $this->persistCounter();
        $addres = $store->sharedIdOf($shared);

        $this->assertTrue(Reclaimer::isProtected($addres));

        $object = new PersistedObject(
            $addres,
            Core::pointerAtAddress('zend_object *', $addres),
            Core::pointerAtAddress('char *', $addres),
            MutableCounter::class,
            'signature',
        );

        $this->expectException(ArenaException::class);
        $this->expectExceptionMessageMatches('/bump-allocated/');

        Reclaimer::reclaimObject($object);
    }
}
