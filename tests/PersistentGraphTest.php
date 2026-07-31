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

namespace Lisachenko\SharedData;

use Lisachenko\SharedData\Stub\GraphNode;
use Lisachenko\SharedData\Stub\ServiceHolder;
use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\PersistentObjectFactory;

/**
 * Deep conversion of object graphs: nesting, diamonds, cycles and their rejection paths
 *
 * Like PersistentStoreTest, every test re-persists its own fresh graph (persist() is an
 * upsert per class-string key) and detaches what it attached.
 */
class PersistentGraphTest extends TestCase
{
    private PersistentStore $store;

    protected function setUp(): void
    {
        $this->store = PersistentStore::boot();
    }

    protected function tearDown(): void
    {
        $this->store->detach();
    }

    /**
     * root -> child -> grandchild, plus arrays on every level
     */
    private function makeChain(): GraphNode
    {
        $grandChild          = new GraphNode('grandchild');
        $grandChild->counter = 3;

        $child            = new GraphNode('child');
        $child->counter   = 2;
        $child->options   = ['features' => ['alpha', 'beta']];
        $child->left      = $grandChild;

        $root            = new GraphNode('root');
        $root->counter   = 1;
        $root->options   = ['db' => ['host' => 'localhost', 'port' => 5432]];
        $root->left      = $child;

        return $root;
    }

    public function testNestedObjectsArePersistedAndReadableThroughTheRoot(): void
    {
        $root = $this->store->persist(GraphNode::class, $this->makeChain());

        $this->assertInstanceOf(GraphNode::class, $root->left);
        $this->assertSame('child', $root->left->name);
        $this->assertSame(2, $root->left->counter);
        $this->assertSame(['alpha', 'beta'], $root->left->options['features']);
        $this->assertInstanceOf(GraphNode::class, $root->left->left);
        $this->assertSame('grandchild', $root->left->left->name);
        $this->assertNull($root->left->left->left);
        $this->assertSame(5432, $root->options['db']['port']);
    }

    public function testNestedStateSurvivesDetachAttachCycles(): void
    {
        $this->store->persist(GraphNode::class, $this->makeChain());

        for ($cycle = 0; $cycle < 10; $cycle++) {
            $this->store->detach();

            $root = $this->store->attach()[GraphNode::class];
            $this->assertSame('child', $root->left->name);
            $this->assertSame(3, $root->left->left->counter);
            $this->assertSame(['alpha', 'beta'], $root->left->options['features']);

            // Frozen semantics reach nested objects: a mutated nested property and a
            // completely replaced nested-object slot must both roll back
            $root->left->name    = "mutated-{$cycle}";
            $root->left->counter = $cycle + 100;
            $root->left->options = ['replaced' => $cycle];
            $root->left          = new GraphNode("replacement-{$cycle}");
            unset($root);
        }

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];
        $this->assertSame('child', $restored->left->name);
        $this->assertSame(2, $restored->left->counter);
        $this->assertSame('grandchild', $restored->left->left->name);
    }

    public function testDiamondKeepsSharedIdentityAcrossRequests(): void
    {
        $shared          = new GraphNode('shared');
        $shared->counter = 42;

        $left          = new GraphNode('left');
        $left->shared  = $shared;
        $right         = new GraphNode('right');
        $right->shared = $shared;

        $source        = new GraphNode('root');
        $source->left  = $left;
        $source->right = $right;

        $root = $this->store->persist(GraphNode::class, $source);

        $this->assertSame($root->left->shared, $root->right->shared);
        $this->assertSame(42, $root->left->shared->counter);
        // Exactly four objects were persisted: root, left, right and ONE shared node
        $this->assertCount(4, array_unique([
            spl_object_id($root),
            spl_object_id($root->left),
            spl_object_id($root->right),
            spl_object_id($root->left->shared),
        ]));

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];

        $this->assertSame($restored->left->shared, $restored->right->shared);
        $this->assertSame('shared', $restored->left->shared->name);
        $this->assertCount(4, array_unique([
            spl_object_id($restored),
            spl_object_id($restored->left),
            spl_object_id($restored->right),
            spl_object_id($restored->right->shared),
        ]));

        // One persisted object seen through both paths: a write is visible from either
        $restored->left->shared->counter = 7;
        $this->assertSame(7, $restored->right->shared->counter);
    }

    public function testSelfReferencingObjectIsPersistedWithoutInfiniteRecursion(): void
    {
        $source         = new GraphNode('self');
        $source->parent = $source;

        $root = $this->store->persist(GraphNode::class, $source);

        $this->assertSame($root, $root->parent);
        $this->assertSame($root, $root->parent->parent->parent);

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];

        $this->assertSame($restored, $restored->parent);
        $this->assertSame('self', $restored->parent->name);
    }

    public function testTwoNodeCycleIsPersistedAndRestored(): void
    {
        $source        = new GraphNode('parent');
        $child         = new GraphNode('child');
        $source->left  = $child;
        $child->parent = $source;

        $root = $this->store->persist(GraphNode::class, $source);

        $this->assertSame($root, $root->left->parent);
        $this->assertSame('child', $root->left->parent->left->name);

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];

        $this->assertSame($restored, $restored->left->parent);

        // Mutating around the cycle still rolls back completely
        $restored->left->name         = 'mutated';
        $restored->left->parent->name = 'mutated-too';
        unset($restored);

        $this->store->detach();
        $final = $this->store->attach()[GraphNode::class];
        $this->assertSame('parent', $final->name);
        $this->assertSame('child', $final->left->name);
    }

    public function testCyclicGraphSurvivesGarbageCollection(): void
    {
        $source        = new GraphNode('gc-root');
        $child         = new GraphNode('gc-child');
        $source->left  = $child;
        $child->parent = $source;

        $root = $this->store->persist(GraphNode::class, $source);

        // A request-side garbage cycle that REFERENCES the persistent graph: the collector
        // buffers it as a possible root and traverses right into the persistent clones
        $holder         = new GraphNode('request-holder');
        $holder->parent = $holder;
        $holder->left   = $root;
        unset($holder);

        $collected = gc_collect_cycles();
        $this->assertGreaterThanOrEqual(0, $collected);

        $this->assertSame('gc-root', $root->name);
        $this->assertSame($root, $root->left->parent);
        unset($root);

        $this->store->detach();
        gc_collect_cycles();

        $restored = $this->store->attach()[GraphNode::class];
        $this->assertSame('gc-child', $restored->left->name);
        $this->assertSame($restored, $restored->left->parent);
    }

    public function testSlotRepointedAtAnotherGraphMemberRollsBack(): void
    {
        $source        = new GraphNode('root');
        $source->left  = new GraphNode('left');
        $source->right = new GraphNode('right');

        $root = $this->store->persist(GraphNode::class, $source);

        // Rolling this back releases a slot that points at a PERSISTENT object: the pin
        // absorbs it and is re-baselined in the same detach pass
        for ($cycle = 0; $cycle < 5; $cycle++) {
            $root->left = $root->right;
            $this->assertSame('right', $root->left->name);
            unset($root);

            $this->store->detach();
            $root = $this->store->attach()[GraphNode::class];
            $this->assertSame('left', $root->left->name);
            $this->assertSame('right', $root->right->name);
            $this->assertNotSame($root->left, $root->right);
        }
    }

    public function testObjectsInsideArraysJoinTheGraph(): void
    {
        $service      = new ServiceHolder();
        $service->dsn = 'sqlite::memory:';

        $source            = new GraphNode('with-services');
        $source->services  = ['db' => $service];

        $root = $this->store->persist(GraphNode::class, $source);

        $this->assertInstanceOf(ServiceHolder::class, $root->services['db']);
        $this->assertSame('sqlite::memory:', $root->services['db']->dsn);

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];
        $this->assertSame('sqlite::memory:', $restored->services['db']->dsn);
    }

    public function testResourceInsideAnObjectInsideAnArrayReportsTheFullPath(): void
    {
        $service      = new ServiceHolder();
        $service->pdo = fopen('php://memory', 'r');

        $source           = new GraphNode('broken');
        $source->services = ['db' => $service];

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$services\[db\]::\$pdo.*resources/');
        $this->store->persist(GraphNode::class, $source);
    }

    public function testNestedRejectionReportsTheNestedPropertyPath(): void
    {
        $source              = new GraphNode('root');
        $source->left        = new GraphNode('child');
        $source->left->left  = new GraphNode('grandchild');
        $source->left->left->options = ['handle' => fopen('php://memory', 'r')];

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$left::\$left::\$options\[handle\]/');
        $this->store->persist(GraphNode::class, $source);
    }

    public function testAlreadyPersistentObjectJoinsTheNewGraphByReference(): void
    {
        $persisted          = $this->store->persist(GraphNode::class, new GraphNode('first-graph'));
        $persisted->counter = 0;

        $second       = new GraphNode('second-graph');
        $second->left = $persisted;

        // Re-persisting under the SAME key: the new graph reaches into the old one, so the
        // old root survives the upsert as a shared member instead of being rejected
        $root = $this->store->persist(GraphNode::class, $second);

        $this->assertSame('second-graph', $root->name);
        $this->assertSame($persisted, $root->left);
        $this->assertSame('first-graph', $root->left->name);

        $this->store->detach();
        $restored = $this->store->attach()[GraphNode::class];
        $this->assertSame('first-graph', $restored->left->name);
    }

    public function testForeignPersistentObjectIsRejected(): void
    {
        // A persistent clone minted outside the store: it looks persistent but no registry
        // record accounts for its lifetime, so it can never join a persisted graph
        $stranger      = new GraphNode('stranger');
        $strangerValue = new ReflectionValue($stranger);
        $foreign       = PersistentObjectFactory::persistentClone($strangerValue->getRawObject());
        $strangerValue->release();

        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $foreign[0]);
        $value->getNativeValue($instance);
        $value->release();

        $source       = new GraphNode('root');
        $source->left = $instance;

        try {
            $this->expectException(NotPersistableException::class);
            $this->expectExceptionMessageMatches('/\$root::\$left.*not registered in this store/');
            $this->store->persist(GraphNode::class, $source);
        } finally {
            // The engine must not see the unregistered clone at teardown
            $source->left = null;
            unset($instance);
        }
    }

    public function testNestedObjectWithDynamicPropertyIsRejectedWithItsPath(): void
    {
        $child = new #[\AllowDynamicProperties] class extends GraphNode {
        };
        $child->undeclared = 'boom';

        $source       = new GraphNode('root');
        $source->left = $child;

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$left::\$undeclared.*dynamic properties/');
        $this->store->persist(GraphNode::class, $source);
    }
}
