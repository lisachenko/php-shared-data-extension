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
 *      recovers the registry from module globals
 *   3. $objects = $store->attach()        - re-registers every persisted object in this
 *      request's object store (fresh handle), rebinds class entries, returns instances;
 *      also arms detach() as a shutdown function
 *   4. ... use the objects; persist() new ones at any time ...
 *   5. detach() runs automatically at request shutdown BEFORE the engine destroys the
 *      object store: rolls property mutations back to the persisted snapshot (frozen
 *      semantics), releases request-owned caches and hides the objects from teardown.
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

    /** @var array<class-string, object> Materialized canonical instances for this request */
    private array $instances = [];

    /** @var array<class-string, int> Object-store handles held during this request */
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
        } else {
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
     * Moves an object's state into persistent memory and returns the canonical instance
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
     * Re-registers every persisted object for the current request
     *
     * @return array<class-string, object> class-string key => canonical instance
     */
    public function attach(): array
    {
        if (!$this->attached) {
            $this->attached = true;
            foreach ($this->registry->all() as $className => $entry) {
                $this->rebindClassEntry($entry);
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
     * Detaches every persisted object from the current request
     *
     * Rolls property mutations back to the persisted snapshot, releases the lazily
     * rebuilt dynamic-properties table, restores the refcount pin and hides the object
     * from the object-store teardown. Runs automatically as a shutdown function; public
     * so worker loops and tests can cycle attach()/detach() manually.
     */
    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }

        // Drop our own references first so only foreign references remain in the count
        $this->instances = [];

        foreach ($this->registry->all() as $name => $entry) {
            $this->restoreSnapshot($entry);

            $objectEntry = ObjectEntry::fromCData($entry->object);

            // Release the request-allocated properties hashtable rebuilt by
            // get_object_vars()/var_dump()/casts, it would dangle next request
            $dynamicProperties = $objectEntry->getDynamicPropertiesPointer();
            if ($dynamicProperties !== null) {
                (new HashTable($dynamicProperties))->releaseReference();
                $objectEntry->setDynamicPropertiesPointer(null);
            }

            $entry->object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;

            if (isset($this->handles[$name])) {
                Core::$executor->objectStore->recycle($this->handles[$name]);
            }
        }

        $this->handles  = [];
        $this->attached = false;
    }

    /**
     * Registers the object for this request and caches the canonical PHP instance
     */
    private function materialize(string $name, PersistedEntry $entry): object
    {
        $handle               = Core::$executor->objectStore->put($entry->object);
        $this->handles[$name] = $handle;

        $entry->object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;

        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $entry->object[0]);
        $value->getNativeValue($instance);
        $value->release();

        $this->instances[$name] = $instance;

        return $instance;
    }

    /**
     * Rebinds the persisted object to this request's class entry, guarding layout drift
     */
    private function rebindClassEntry(PersistedEntry $entry): void
    {
        $classValue = Core::$executor->classTable->find(strtolower($entry->className));
        if ($classValue === null) {
            throw new \RuntimeException(
                "Class {$entry->className} is not loaded; load or preload it before attach()",
            );
        }
        $classEntry = $classValue->getRawClass();

        $signature = Persister::computeSignature($classEntry);
        if ($signature !== $entry->signature) {
            throw new \RuntimeException(
                "Class {$entry->className} changed its property layout since the object was persisted; " .
                'drop the persisted entry or restart the worker',
            );
        }

        $entry->object->ce = $classEntry;
    }

    /**
     * Rolls the inline property slots back to the frozen snapshot
     */
    private function restoreSnapshot(PersistedEntry $entry): void
    {
        $count = $entry->object->ce->default_properties_count;
        if ($count === 0) {
            return;
        }
        $zvalSize  = Core::sizeof(Core::type('zval'));
        $tableBase = Core::cast('zval *', Core::addr($entry->object->properties_table[0]));
        $frozen    = Core::cast('zval *', $entry->snapshot);

        for ($index = 0; $index < $count; $index++) {
            $slot = Core::addr($tableBase[$index]);
            // Persisted slots are never refcounted (scalars + immutable payloads), so a
            // refcounted slot always means a request-time mutation that must be released
            $isRefcounted = ($slot->u1->type_info & (1 << Core::engineConstant('Z_TYPE_FLAGS_SHIFT'))) !== 0;
            if ($isRefcounted) {
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
