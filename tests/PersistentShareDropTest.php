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

use Lisachenko\SharedData\Stub\GraphAlpha;
use Lisachenko\SharedData\Stub\GraphBeta;
use Lisachenko\SharedData\Stub\GraphNode;
use PHPUnit\Framework\TestCase;

/**
 * Cross-graph object sharing and drop() with memory reclamation
 *
 * Every test leaves the registry as it found it (tearDown drops the keys it uses), so
 * objectCount() deltas are meaningful even though the persistent registry lives for the
 * whole test process.
 */
class PersistentShareDropTest extends TestCase
{
    /** @var list<class-string> Keys these tests may leave behind */
    private const KEYS = [GraphAlpha::class, GraphBeta::class];

    private PersistentStore $store;

    protected function setUp(): void
    {
        $this->store = PersistentStore::boot();
    }

    protected function tearDown(): void
    {
        $this->store->detach();

        foreach (self::KEYS as $key) {
            if ($this->store->has($key)) {
                $this->store->drop($key);
            }
        }

        $this->store->detach();
    }

    public function testObjectPersistedInOneGraphIsSharedByAnother(): void
    {
        $sourceAlpha       = new GraphAlpha('alpha');
        $sourceAlpha->left = new GraphNode('shared-child');

        $alpha  = $this->store->persist(GraphAlpha::class, $sourceAlpha);
        $shared = $alpha->left;

        $sourceBeta       = new GraphBeta('beta');
        $sourceBeta->left = $shared;

        $beta = $this->store->persist(GraphBeta::class, $sourceBeta);

        // The shared node was referenced, not copied: one object, two graphs
        $this->assertSame($shared, $beta->left);
        $this->assertSame('shared-child', $beta->left->name);
        $this->assertSame(spl_object_id($alpha->left), spl_object_id($beta->left));

        unset($alpha, $beta, $shared, $sourceAlpha, $sourceBeta);

        // ... and identity survives the request boundary
        for ($cycle = 0; $cycle < 5; $cycle++) {
            $this->store->detach();

            $objects = $this->store->attach();
            $this->assertSame($objects[GraphAlpha::class]->left, $objects[GraphBeta::class]->left);
            $this->assertSame('shared-child', $objects[GraphBeta::class]->left->name);
            unset($objects);
        }
    }

    public function testOneRootStoredUnderTwoKeysSurvivesDroppingOneOfThem(): void
    {
        $source          = new GraphAlpha('two-keys');
        $source->counter = 11;

        $alpha = $this->store->persist(GraphAlpha::class, $source);

        // The persisted alpha root joins a second entry by reference: two entries, one
        // shared graph, and dropping either must leave the other completely functional
        $sourceBeta       = new GraphBeta('holder');
        $sourceBeta->left = $alpha;
        $beta             = $this->store->persist(GraphBeta::class, $sourceBeta);

        $this->assertSame($alpha, $beta->left);
        unset($alpha, $beta, $source, $sourceBeta);

        $this->assertTrue($this->store->drop(GraphAlpha::class));
        $this->assertFalse($this->store->has(GraphAlpha::class));

        // The shared root is still very much alive through the surviving entry
        for ($cycle = 0; $cycle < 5; $cycle++) {
            $this->store->detach();

            $restored = $this->store->attach()[GraphBeta::class];
            $this->assertSame('two-keys', $restored->left->name);
            $this->assertSame(11, $restored->left->counter);
            unset($restored);
        }
    }

    public function testDroppedEntryDisappearsFromEveryAccessPath(): void
    {
        $this->store->persist(GraphAlpha::class, new GraphAlpha('doomed'));
        $this->assertTrue($this->store->has(GraphAlpha::class));

        $this->assertTrue($this->store->drop(GraphAlpha::class));

        $this->assertFalse($this->store->has(GraphAlpha::class));
        $this->assertNull($this->store->get(GraphAlpha::class));
        $this->assertArrayNotHasKey(GraphAlpha::class, $this->store->attach());

        // A fresh request must not resurrect it either
        $this->store->detach();
        $this->assertArrayNotHasKey(GraphAlpha::class, $this->store->attach());
        $this->assertNull($this->store->get(GraphAlpha::class));
    }

    public function testTheSameClassCanBePersistedAgainAfterDropping(): void
    {
        $this->store->persist(GraphAlpha::class, new GraphAlpha('first'));
        $this->assertTrue($this->store->drop(GraphAlpha::class));

        $second          = new GraphAlpha('second');
        $second->counter = 99;
        $second->options = ['fresh' => ['state' => true]];

        $root = $this->store->persist(GraphAlpha::class, $second);
        $this->assertSame('second', $root->name);
        $this->assertSame(99, $root->counter);
        $this->assertTrue($root->options['fresh']['state']);
        unset($root, $second);

        $this->store->detach();
        $restored = $this->store->attach()[GraphAlpha::class];
        $this->assertSame('second', $restored->name);
        $this->assertSame(99, $restored->counter);
    }

    public function testDropReturnsFalseForAnUnknownKey(): void
    {
        $this->assertFalse($this->store->has(GraphBeta::class));
        $this->assertFalse($this->store->drop(GraphBeta::class));
    }

    public function testDropRefusesToFreeObjectsTheRequestStillHolds(): void
    {
        $baseline = $this->store->objectCount();

        $source       = new GraphAlpha('aliased');
        $source->left = new GraphNode('aliased-child');

        $root  = $this->store->persist(GraphAlpha::class, $source);
        $child = $root->left;

        // A nested member is enough: it would be freed together with the entry
        unset($source, $root);

        try {
            $this->store->drop(GraphAlpha::class);
            $this->fail('drop() must refuse to free an object the request still references');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(GraphAlpha::class, $exception->getMessage());
            $this->assertStringContainsString(GraphNode::class, $exception->getMessage());
        }

        // The refused drop left the registry AND the store's instance cache untouched
        $this->assertTrue($this->store->has(GraphAlpha::class));
        $this->assertSame($baseline + 2, $this->store->objectCount());
        $this->assertSame('aliased-child', $child->name);

        $live = $this->store->get(GraphAlpha::class);
        $this->assertInstanceOf(GraphAlpha::class, $live);
        $this->assertSame('aliased', $live->name);
        $this->assertSame($child, $live->left);

        // Once every alias is gone the very same drop succeeds
        unset($live, $child);

        $this->assertTrue($this->store->drop(GraphAlpha::class));
        $this->assertFalse($this->store->has(GraphAlpha::class));
        $this->assertSame($baseline, $this->store->objectCount());
    }

    public function testSharedObjectOutlivesTheFirstEntryAndDiesWithTheLast(): void
    {
        $baseline = $this->store->objectCount();

        $sourceAlpha       = new GraphAlpha('alpha');
        $sourceAlpha->left = new GraphNode('shared');
        $alpha             = $this->store->persist(GraphAlpha::class, $sourceAlpha);
        $shared            = $alpha->left;

        $this->assertSame($baseline + 2, $this->store->objectCount());

        $sourceBeta       = new GraphBeta('beta');
        $sourceBeta->left = $shared;
        $this->store->persist(GraphBeta::class, $sourceBeta);

        // Only the new root was minted; the shared node was referenced
        $this->assertSame($baseline + 3, $this->store->objectCount());
        unset($alpha, $shared, $sourceAlpha, $sourceBeta);

        $this->assertTrue($this->store->drop(GraphAlpha::class));

        // The alpha root is gone, the shared node stays: one entry still references it
        $this->assertSame($baseline + 2, $this->store->objectCount());

        $this->store->detach();
        $beta = $this->store->attach()[GraphBeta::class];
        $this->assertSame('shared', $beta->left->name);
        unset($beta);

        $this->assertTrue($this->store->drop(GraphBeta::class));
        $this->assertSame($baseline, $this->store->objectCount());
    }

    public function testUpsertReleasesTheSupersededGraph(): void
    {
        $baseline = $this->store->objectCount();

        for ($generation = 0; $generation < 10; $generation++) {
            $source          = new GraphAlpha("generation-{$generation}");
            $source->left    = new GraphNode('child');
            $source->options = ['payload' => ['deep' => ['a', 'b', 'c']]];

            $root = $this->store->persist(GraphAlpha::class, $source);
            $this->assertSame("generation-{$generation}", $root->name);
            $this->assertSame('c', $root->options['payload']['deep'][2]);
            unset($root, $source);

            // Every generation replaces the previous one completely: two objects per
            // entry, never more, no matter how many times the key is overwritten
            $this->assertSame($baseline + 2, $this->store->objectCount());
        }

        $this->assertTrue($this->store->drop(GraphAlpha::class));
        $this->assertSame($baseline, $this->store->objectCount());
    }

    public function testRequestObjectsMayHoldFirstClassReferencesToPersistedObjects(): void
    {
        $source       = new GraphAlpha('referenced');
        $source->left = new GraphNode('referenced-child');

        $root = $this->store->persist(GraphAlpha::class, $source);
        unset($source);

        // A plain request object with a persistent object in a property slot, inside a
        // reference cycle so the collector is forced to traverse into persistent memory
        $holder         = new GraphNode('request-holder');
        $holder->parent = $holder;
        $holder->left   = $root->left;
        $holder->shared = $root;

        gc_collect_cycles();

        $this->assertSame('referenced-child', $holder->left->name);
        $this->assertSame('referenced', $holder->shared->name);
        $this->assertSame($root->left, $holder->left);

        // Persisted objects keep working after the request object is collected
        unset($holder);
        gc_collect_cycles();
        $this->assertSame('referenced-child', $root->left->name);
        unset($root);

        $this->store->detach();
        $restored = $this->store->attach()[GraphAlpha::class];
        $this->assertSame('referenced-child', $restored->left->name);
    }

    public function testDropAndRePersistCyclesKeepTheObjectTableFlat(): void
    {
        $baseline = $this->store->objectCount();

        for ($cycle = 0; $cycle < 25; $cycle++) {
            $source           = new GraphAlpha("cycle-{$cycle}");
            $source->left     = new GraphNode('child');
            $source->services = ['nested' => new GraphNode('in-array')];
            $source->options  = ['numbers' => [1, 2, 3], 'flag' => true];

            $root = $this->store->persist(GraphAlpha::class, $source);
            $this->assertSame('in-array', $root->services['nested']->name);
            unset($root, $source);

            $this->store->detach();
            $attached = $this->store->attach()[GraphAlpha::class];
            $this->assertSame("cycle-{$cycle}", $attached->name);
            unset($attached);

            $this->assertTrue($this->store->drop(GraphAlpha::class));
            $this->assertSame($baseline, $this->store->objectCount());
        }
    }
}
