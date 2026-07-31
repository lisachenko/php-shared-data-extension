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

use FFI\CData;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;

/**
 * PHP objects that survive the request boundary (per worker process)
 *
 * Lifecycle per request:
 *   1. Core::init() / Core::preload() (z-engine)
 *   2. $store = PersistentStore::boot()   - registers/reattaches the persistent module,
 *      recovers the registry from module globals and verifies its layout version
 *   3. $objects = $store->attach()        - re-registers EVERY object of every persisted
 *      graph in this request's object store (fresh handle each), rebinds their class
 *      entries and returns the graph roots; also arms detach() as a shutdown function
 *   4. ... use the objects; persist() new ones at any time ...
 *   5. detach() runs automatically at request shutdown BEFORE the engine destroys the
 *      object store: rolls every graph object's properties back to its persisted snapshot
 *      (frozen semantics), releases request-owned caches and hides the objects from
 *      teardown.
 *
 * A persisted entry is a whole object GRAPH (see Persister): the store keys instances by
 * the graph root, nested objects are reached through the root's properties and keep their
 * identity across requests.
 *
 * persist() returns a NEW canonical persistent instance: zvals embed zend_object
 * pointers directly, so existing references to the source object cannot be retargeted.
 * Always continue with the returned instance; the source stays an ordinary object.
 */
final class PersistentStore
{
    /**
     * Stores booted during this request, keyed by module name (request-scoped: PHP
     * statics reset per request, exactly like the shutdown functions the stores arm)
     *
     * @var array<string, self>
     */
    private static array $activeStores = [];

    private Registry $registry;

    private Persister $persister;

    /** @var array<class-string, object> Materialized canonical graph roots for this request */
    private array $instances = [];

    /**
     * Object-store handles held during this request: one per object of each persisted
     * graph, in graph index order
     *
     * @var array<class-string, list<int>>
     */
    private array $handles = [];

    private bool $attached = false;

    private bool $shutdownArmed = false;

    private function __construct(Registry $registry)
    {
        $this->registry  = $registry;
        $this->persister = new Persister();
    }

    /**
     * Boots (or re-attaches) the persistent module and recovers the registry
     */
    public static function boot(string $moduleName = 'shared_objects'): self
    {
        $module = new ObjectPersistenceModule($moduleName);
        if (!$module->isModuleRegistered()) {
            $module->register();
            $module->startup();
        }

        $globals = $module->getGlobals();
        if ($globals === null) {
            throw new \RuntimeException('Persistent module globals are not available');
        }

        if ($globals[0] === 0) {
            [$registry, $address] = Registry::create();
            $globals[0]           = $address;
            $globals[1]           = Registry::LAYOUT_VERSION;
        } else {
            if ($globals[1] !== Registry::LAYOUT_VERSION) {
                throw new \RuntimeException(sprintf(
                    'Persistent registry of module %s uses layout version %d, this build expects %d; ' .
                    'restart the worker to rebuild the persisted state',
                    $moduleName,
                    $globals[1],
                    Registry::LAYOUT_VERSION,
                ));
            }
            $registry = Registry::fromAddress($globals[0]);
        }

        $store = new self($registry);

        self::$activeStores[$moduleName] = $store;

        return $store;
    }

    /**
     * Detaches every store booted during this request (idempotent per store)
     *
     * Invoked by ObjectPersistenceModule::requestShutdown() as the belt-and-braces
     * request-end path; stores normally detach through their own shutdown function.
     */
    public static function detachActiveStores(): void
    {
        foreach (self::$activeStores as $store) {
            $store->detach();
        }
    }

    /**
     * Moves an object graph's state into persistent memory and returns the canonical root
     *
     * Every object reachable from $object through property slots (directly or through
     * arrays) is persisted with it, exactly once, so shared sub-objects keep their
     * identity and cycles are fine.
     *
     * Storage is keyed by class (or interface) name, so static analyzers infer the
     * instance type from the key: `$store->get(AppConfig::class)` is an AppConfig. The
     * instance is immediately live for the current request; on later requests it is
     * re-materialized by attach() under the same key.
     *
     * @template T of object
     *
     * @param class-string<T> $className Storage key; the object must be an instance of it
     * @param T               $object
     *
     * @return T The canonical persistent instance
     */
    public function persist(string $className, object $object): object
    {
        if (!$object instanceof $className) {
            throw new \InvalidArgumentException(sprintf(
                'Storage key %s must name a class or interface of the persisted instance %s',
                $className,
                get_class($object),
            ));
        }
        $this->attach();

        $entry = $this->persister->persistObject($object);
        $this->registry->store($className, $entry);

        /** @var T */
        return $this->materialize($className, $entry);
    }

    /**
     * Re-registers every object of every persisted graph for the current request
     *
     * @return array<class-string, object> class-string key => canonical graph root
     */
    public function attach(): array
    {
        if (!$this->attached) {
            $this->attached = true;
            foreach ($this->registry->all() as $className => $entry) {
                $this->rebindClassEntries($entry);
                $this->materialize($className, $entry);
            }
            $this->armShutdown();
        }

        return $this->instances;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T|null
     */
    public function get(string $className): ?object
    {
        $this->attach();

        $instance = $this->instances[$className] ?? null;
        \assert($instance === null || $instance instanceof $className);

        return $instance;
    }

    /**
     * @param class-string $className
     */
    public function has(string $className): bool
    {
        return $this->registry->has($className);
    }

    /**
     * Detaches every persisted graph from the current request
     *
     * For every object of every graph: rolls property mutations back to the persisted
     * snapshot, releases the lazily rebuilt dynamic-properties table, restores the
     * refcount pin and hides the object from the object-store teardown. Runs
     * automatically as a shutdown function; public so worker loops and tests can cycle
     * attach()/detach() manually.
     */
    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }

        // Drop our own references first so only foreign references remain in the count
        $this->instances = [];

        /** @var array<string, PersistedEntry> $entries */
        $entries = iterator_to_array($this->registry->all());

        foreach ($entries as $entry) {
            foreach ($entry->objects as $index => $object) {
                $this->restoreSnapshot($object, $entry->snapshots[$index]);

                $objectEntry = ObjectEntry::fromCData($object);

                // Release the request-allocated properties hashtable rebuilt by
                // get_object_vars()/var_dump()/casts, it would dangle next request
                $dynamicProperties = $objectEntry->getDynamicPropertiesPointer();
                if ($dynamicProperties !== null) {
                    (new HashTable($dynamicProperties))->releaseReference();
                    $objectEntry->setDynamicPropertiesPointer(null);
                }
            }
        }

        // Pins are re-baselined only after EVERY rollback is done: a slot the request
        // pointed at another persistent object is released above, which decrements that
        // object's pin - possibly one that was already processed in this same pass
        foreach ($entries as $name => $entry) {
            foreach ($entry->objects as $object) {
                $object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;
            }

            foreach ($this->handles[$name] ?? [] as $handle) {
                Core::$executor->objectStore->recycle($handle);
            }
        }

        $this->handles  = [];
        $this->attached = false;
    }

    /**
     * Registers every graph object for this request and caches the canonical root instance
     *
     * Nested objects need their own object-store handle (spl_object_id, GC and every
     * engine path that resolves an object by handle depend on it), but only the root is
     * materialized as a PHP instance - the rest is reached through property slots, which
     * point at the very same pinned clones.
     */
    private function materialize(string $name, PersistedEntry $entry): object
    {
        $handles = [];
        foreach ($entry->objects as $object) {
            $handles[]            = Core::$executor->objectStore->put($object);
            $object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;
        }
        $this->handles[$name] = $handles;

        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $entry->root()[0]);
        $value->getNativeValue($instance);
        $value->release();

        $this->instances[$name] = $instance;

        return $instance;
    }

    /**
     * Rebinds every graph object to this request's class entry, guarding layout drift
     */
    private function rebindClassEntries(PersistedEntry $entry): void
    {
        foreach ($entry->objects as $index => $object) {
            $className  = $entry->classNames[$index];
            $classValue = Core::$executor->classTable->find(strtolower($className));
            if ($classValue === null) {
                throw new \RuntimeException(
                    "Class {$className} is not loaded; load or preload it before attach()",
                );
            }
            $classEntry = $classValue->getRawClass();

            $signature = Persister::computeSignature($classEntry);
            if ($signature !== $entry->signatures[$index]) {
                throw new \RuntimeException(
                    "Class {$className} changed its property layout since the object was persisted; " .
                    'drop the persisted entry or restart the worker',
                );
            }

            $object->ce = $classEntry;
        }
    }

    /**
     * Rolls the inline property slots of one graph object back to its frozen snapshot
     *
     * A persisted slot is refcounted only when it points at another graph object, so
     * "refcounted" no longer implies "mutated by the request". The honest test is a
     * comparison against the frozen image: a slot whose payload word or type_info drifted
     * holds a request-time value and must be released before the frozen bytes come back.
     * Releasing a slot the request pointed at some other persistent clone only decrements
     * a pinned refcount - harmless, and every pin is re-baselined in this same pass.
     */
    private function restoreSnapshot(CData $object, CData $snapshot): void
    {
        $count = $object->ce->default_properties_count;
        if ($count === 0) {
            return;
        }
        $zvalSize     = Core::sizeof(Core::type('zval'));
        $tableBase    = Core::cast('zval *', Core::addr($object->properties_table[0]));
        $frozen       = Core::cast('zval *', $snapshot);
        $refcountedFlag = 1 << Core::engineConstant('Z_TYPE_FLAGS_SHIFT');

        for ($index = 0; $index < $count; $index++) {
            $slot       = Core::addr($tableBase[$index]);
            $frozenSlot = Core::addr($frozen[$index]);

            // Word-wise compare of value (2 words) + type_info; reading the payload
            // pointer itself is not an option (a NULL union member surfaces as PHP null)
            $liveWords   = Core::cast('uint32_t *', $slot);
            $frozenWords = Core::cast('uint32_t *', $frozenSlot);
            $isMutated   = $liveWords[0] !== $frozenWords[0]
                || $liveWords[1] !== $frozenWords[1]
                || $liveWords[2] !== $frozenWords[2];

            if ($isMutated && ($slot->u1->type_info & $refcountedFlag) !== 0) {
                Core::call('zval_ptr_dtor', $slot);
            }
        }
        Core::memcpy(Core::cast('char *', $tableBase), Core::cast('char *', $frozen), $count * $zvalSize);
    }

    /**
     * Arms detach() to run before the engine tears the object store down
     */
    private function armShutdown(): void
    {
        if (!$this->shutdownArmed) {
            $this->shutdownArmed = true;
            register_shutdown_function(function (): void {
                $this->detach();
            });
        }
    }
}
