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

use Lisachenko\SharedData\Stub\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The persistent module and registry survive for the whole test process (that is the
 * feature), so every test uses unique object names and detaches what it attached.
 */
class PersistentStoreTest extends TestCase
{
    private static int $sequence = 0;

    private PersistentStore $store;

    private string $name;

    protected function setUp(): void
    {
        $this->store = PersistentStore::boot();
        $this->name  = 'object-' . self::$sequence++;
    }

    protected function tearDown(): void
    {
        $this->store->detach();
    }

    private function makeConfig(): AppConfig
    {
        $config            = new AppConfig();
        $config->env       = 'production';
        $config->bootCount = 1;
        $config->label     = 'primary';
        $config->settings  = [
            'db'       => ['host' => 'localhost', 'port' => 5432],
            'debug'    => false,
            'features' => ['alpha', 'beta'],
        ];

        return $config;
    }

    public function testPersistReturnsCanonicalWorkingInstance(): void
    {
        $persisted = $this->store->persist($this->name, $this->makeConfig());

        $this->assertInstanceOf(AppConfig::class, $persisted);
        $this->assertSame('production', $persisted->env);
        $this->assertSame(1, $persisted->bootCount);
        $this->assertSame('primary', $persisted->label);
        $this->assertSame(5432, $persisted->settings['db']['port']);
        $this->assertSame('beta', $persisted->settings['features'][1]);
        $this->assertSame('hidden', $persisted->revealSecret());
        $this->assertSame(['a' => 1], $persisted->revealInternal());

        // Identity is stable within the request
        $this->assertSame($persisted, $this->store->get($this->name));
        $this->assertTrue($this->store->has($this->name));
    }

    public function testStateSurvivesDetachAttachCycles(): void
    {
        $this->store->persist($this->name, $this->makeConfig());

        for ($cycle = 0; $cycle < 25; $cycle++) {
            $this->store->detach();

            $objects = $this->store->attach();
            $config  = $objects[$this->name];
            $this->assertSame('production', $config->env);
            $this->assertSame(1, $config->bootCount);
            $this->assertSame(['alpha', 'beta'], $config->settings['features']);

            // Mutations inside a cycle must not survive into the next one
            $config->env       = "mutated-{$cycle}";
            $config->bootCount = $cycle + 100;
            $config->settings  = ['replaced' => $cycle];
            unset($config, $objects);
        }
    }

    public function testMutationsRollBackToFrozenSnapshot(): void
    {
        $config = $this->store->persist($this->name, $this->makeConfig());

        $config->env       = 'mutated-' . str_repeat('x', 64);
        $config->bootCount = 999;
        $config->settings['db']['host'] = 'elsewhere';

        // Force the engine to build the dynamic-properties cache while mutated
        $vars = get_object_vars($config);
        $this->assertSame(999, $vars['bootCount']);
        unset($config, $vars);

        $this->store->detach();
        $restored = $this->store->attach()[$this->name];

        $this->assertSame('production', $restored->env);
        $this->assertSame(1, $restored->bootCount);
        $this->assertSame('localhost', $restored->settings['db']['host']);
    }

    public function testSecondStoreRecoversRegistryFromModuleGlobals(): void
    {
        $this->store->persist($this->name, $this->makeConfig());
        $this->store->detach();

        // A fresh store instance only shares the persistent module globals - this is
        // the same recovery path a new request takes in a worker
        $secondStore = PersistentStore::boot();
        try {
            $this->assertTrue($secondStore->has($this->name));
            $recovered = $secondStore->get($this->name);
            $this->assertInstanceOf(AppConfig::class, $recovered);
            $this->assertSame('production', $recovered->env);
            $this->assertSame(1, $recovered->bootCount);
            $this->assertSame(5432, $recovered->settings['db']['port']);
        } finally {
            $secondStore->detach();
        }
    }

    public function testArraysAreCopiedOnWriteNotShared(): void
    {
        $config = $this->store->persist($this->name, $this->makeConfig());

        $copy = $config->settings;
        $copy['db']['host'] = 'far-away';

        $this->assertSame('localhost', $config->settings['db']['host']);
        $this->assertSame('far-away', $copy['db']['host']);
    }

    public function testResourcePropertyIsRejected(): void
    {
        $candidate      = new class {
            public mixed $handle = null;
        };
        $candidate->handle = fopen('php://memory', 'r');

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$handle.*resources/');
        $this->store->persist($this->name, $candidate);
    }

    public function testNestedObjectIsRejected(): void
    {
        $candidate        = new class {
            public mixed $inner = null;
        };
        $candidate->inner = new \stdClass();

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$inner.*nested objects/');
        $this->store->persist($this->name, $candidate);
    }

    public function testClosurePropertyIsRejected(): void
    {
        $candidate          = new class {
            public mixed $callback = null;
        };
        $candidate->callback = static fn (): int => 42;

        $this->expectException(NotPersistableException::class);
        $this->store->persist($this->name, $candidate);
    }

    public function testInternalClassIsRejected(): void
    {
        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/internal classes/');
        $this->store->persist($this->name, new \ArrayObject([1, 2, 3]));
    }

    public function testDynamicPropertyIsRejected(): void
    {
        $candidate      = new \stdClass();
        $candidate->foo = 'bar';

        $this->expectException(NotPersistableException::class);
        $this->store->persist($this->name, $candidate);
    }

    public function testNestedArrayObjectValueIsRejectedWithPath(): void
    {
        $config           = new AppConfig();
        $config->settings = ['services' => ['logger' => new \stdClass()]];

        $this->expectException(NotPersistableException::class);
        $this->expectExceptionMessageMatches('/\$root::\$settings\[services\]\[logger\]/');
        $this->store->persist($this->name, $config);
    }

    public function testDestructorNeverRunsForPersistedObjects(): void
    {
        $candidate = new class {
            public static bool $destructed = false;

            public int $value = 42;

            public function __destruct()
            {
                self::$destructed = true;
            }
        };

        $persisted = $this->store->persist($this->name, $candidate);
        unset($persisted);
        $this->store->detach();
        gc_collect_cycles();

        // The original candidate is destroyed normally, the persistent clone never is;
        // resetting the flag isolates the persisted instance's behaviour
        $candidate::$destructed = false;
        $this->store->attach();
        $this->store->detach();

        $this->assertFalse($candidate::$destructed);
    }
}
