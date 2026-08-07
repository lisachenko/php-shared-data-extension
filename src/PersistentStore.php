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
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;

/**
 * PHP objects that survive the request boundary (per worker process)
 *
 * Lifecycle per request:
 *   1. Core::init() / Core::preload() (z-engine)
 *   2. $store = PersistentStore::boot()   - registers/reattaches the persistent module,
 *      recovers the registry from module globals and verifies its layout version
 *   3. $objects = $store->attach()        - re-registers EVERY persisted object in this
 *      request's object store (fresh handle each), rebinds their class entries and
 *      returns the graph roots; also arms detach() as a shutdown function
 *   4. ... use the objects; persist() new ones or drop() old ones at any time ...
 *   5. detach() runs automatically at request shutdown BEFORE the engine destroys the
 *      object store: rolls every persisted object's properties back to its snapshot
 *      (frozen semantics), releases request-owned caches and hides the objects from
 *      teardown.
 *
 * A persisted entry is a whole object GRAPH (see Persister), keyed by a class-string. The
 * graph is described as a list of MEMBER objects living in one process-wide object table,
 * so entries may share members: persisting an object that already belongs to another entry
 * references the existing clone instead of copying it, and identity holds across entries
 * and across requests. Every object counts how many entries reference it, which is what
 * lets drop() reclaim memory without ever pulling an object out from under a live graph.
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
     * Object-store handles held during this request, keyed by persistent clone ADDRESS
     *
     * Keying by address rather than by entry is what keeps a shared object registered
     * exactly once per request, no matter how many entries reach it.
     *
     * @var array<int, int>
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
     * identity and cycles are fine. Objects that are ALREADY persistent in this store
     * are referenced rather than copied: a graph may reach into another entry's graph,
     * and both entries then own the shared objects jointly.
     *
     * Storage is keyed by class (or interface) name, so static analyzers infer the
     * instance type from the key: `$store->get(AppConfig::class)` is an AppConfig. The
     * instance is immediately live for the current request; on later requests it is
     * re-materialized by attach() under the same key.
     *
     * Persisting over an existing key is an upsert: the previous graph is released with
     * exactly the same accounting as drop(), including the alias-safety check - so a
     * request that still holds instances of objects only the previous graph referenced
     * gets a RuntimeException instead of freed memory under its feet.
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

        $entry = $this->persister->persistObject(
            $object,
            fn (int $address): ?PersistedObject => $this->registry->findObject($address),
        );

        // Hydrated BEFORE the upsert overwrites the record, and released AFTER the new
        // members were share-incremented: an object belonging to both generations must
        // never transit through a share count of zero. Members the new graph keeps
        // referencing are protected, so only the truly superseded ones are candidates
        $previous   = $this->registry->findEntry($className);
        $candidates = [];
        if ($previous !== null) {
            $candidates = $this->guardedCandidates($className, $previous, $entry->members);
        }

        $this->registry->store($className, $entry);

        if ($previous !== null) {
            // The name already points at the new record - only the superseded generation
            // has to be released, never the key itself
            $this->releaseEntry($className, $previous, $candidates, false);
        }

        /** @var T */
        return $this->materialize($className, $entry);
    }

    /**
     * Removes a persisted graph and reclaims every object no other entry still references
     *
     * Returns false when nothing is stored under $className - dropping what is not there
     * is not an error. Objects shared with other entries survive with their share count
     * decremented; only members that no entry references anymore are freed (their sealed
     * arrays, snapshot buffers, clone blocks and metadata tables all go back to the
     * process allocator - see Reclaimer for what is deliberately kept).
     *
     * Alias safety: an object may only be freed while nothing in this request can still
     * reach it. Userland copies of an object zval addref even a pinned persistent clone,
     * so a live alias is detectable - if any object about to be freed sits above the pin
     * baseline, this throws a RuntimeException and leaves the registry completely intact
     * (the check runs before any mutation). Release your references, then drop again.
     *
     * ARRAY payloads cannot be checked this way: immutable arrays live in non-refcounted
     * zvals, so a copy taken earlier in this request leaves no trace. Copies of a dropped
     * entry's arrays must not be used after drop() returns; across requests the question
     * cannot arise, since request memory dies with its request.
     *
     * @param class-string $className Storage key of the graph to remove
     *
     * @return bool Whether an entry was actually removed
     */
    public function drop(string $className): bool
    {
        // Attach first so handle state is consistent no matter when drop() is called
        $this->attach();

        $entry = $this->registry->findEntry($className);
        if ($entry === null) {
            return false;
        }

        $candidates = $this->guardedCandidates($className, $entry, []);

        $this->releaseEntry($className, $entry, $candidates, true);

        return true;
    }

    /**
     * Re-registers every persisted object for the current request
     *
     * @return array<class-string, object> class-string key => canonical graph root
     */
    public function attach(): array
    {
        if (!$this->attached) {
            $this->attached = true;

            // ONE pass over the global object table: a shared object is rebound, registered
            // and pinned exactly once, however many entries reach it
            foreach ($this->registry->allObjects() as $address => $object) {
                $this->rebindClassEntry($object);
                $this->register($address, $object->object);
            }

            foreach ($this->registry->allEntries() as $className => $entry) {
                $this->instances[$className] = self::instanceOf($this->rootObjectOf($entry));
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
     * Number of persistent object clones currently held by the registry
     *
     * Shared objects are counted once. Useful as a reclamation gauge in tests and worker
     * diagnostics; the same number is shown in the module's phpinfo() section.
     */
    public function objectCount(): int
    {
        return $this->registry->objectCount();
    }

    /**
     * Detaches every persisted object from the current request
     *
     * For every persisted object: rolls property mutations back to the persisted
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

        /** @var list<PersistedObject> $objects */
        $objects = iterator_to_array($this->registry->allObjects(), false);

        foreach ($objects as $object) {
            $this->restoreSnapshot($object->object, $object->snapshot);

            $objectEntry = ObjectEntry::fromCData($object->object);

            // Release the request-allocated properties hashtable rebuilt by
            // get_object_vars()/var_dump()/casts, it would dangle next request.
            // Mirrors zend_array_release(): drop our reference, and let the
            // engine dismantle the table through its own allocator at zero
            $dynamicProperties = $objectEntry->getDynamicPropertiesPointer();
            if ($dynamicProperties !== null) {
                $gcHeader           = $dynamicProperties->gc;
                $gcHeader->refcount = $gcHeader->refcount - 1;
                if ($gcHeader->refcount === 0) {
                    Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $dynamicProperties));
                }
                $objectEntry->setDynamicPropertiesPointer(null);
            }
        }

        // Pins are re-baselined only after EVERY rollback is done: a slot the request
        // pointed at another persistent object is released above, which decrements that
        // object's pin - possibly one that was already processed in this same pass
        foreach ($objects as $object) {
            $object->object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;
        }

        foreach ($this->handles as $handle) {
            Core::$executor->objectStore->recycle($handle);
        }

        $this->handles  = [];
        $this->attached = false;
    }

    /**
     * Determines what releasing $entry would free, and refuses if the request can still see it
     *
     * Runs BEFORE any registry state is touched, and gives back a store that is exactly as
     * it was if it refuses: the store's own cached root instance is released first (it is
     * bookkeeping, not a user alias, and would otherwise mask every honest count) and
     * re-materialized from the untouched entry when the check fails.
     *
     * @param list<int> $protected Member addresses a NEW generation of this entry keeps
     *                             referencing; those can never reach a share count of zero
     *
     * @return list<PersistedObject> Members that releasing this entry would reclaim
     */
    private function guardedCandidates(string $className, PersistedEntry $entry, array $protected): array
    {
        $hadInstance = isset($this->instances[$className]);
        unset($this->instances[$className]);

        // A member whose last referencing entry is this one, and which no successor keeps
        $candidates = [];
        foreach ($entry->members as $address) {
            $object = $this->registry->findObject($address);
            if ($object !== null && $object->shares <= 1 && !\in_array($address, $protected, true)) {
                $candidates[] = $object;
            }
        }

        foreach ($candidates as $candidate) {
            // Userland copies of an object zval addref even a pinned persistent clone, so
            // anything off the baseline means the request can still reach this object
            if ($candidate->object->gc->refcount === PersistentObjectFactory::PIN_BASELINE) {
                continue;
            }
            if ($hadInstance) {
                $this->instances[$className] = self::instanceOf($this->rootObjectOf($entry));
            }

            throw new \RuntimeException(sprintf(
                'Cannot release %s: the request still holds a reference to the persisted %s instance ' .
                'that would be freed. Release every variable, property and array element pointing at ' .
                'the graph (unset() them, or let their scope end) before dropping or replacing the entry.',
                $className,
                $candidate->className,
            ));
        }

        return $candidates;
    }

    /**
     * Removes one entry, decrements its members' shares and reclaims what nobody needs
     *
     * Called only with the candidate list guardedCandidates() has already vetted, so
     * nothing below this line can fail on user state.
     *
     * @param list<PersistedObject> $candidates Members to reclaim once their share hits zero
     * @param bool                  $unlink     Whether the NAME still points at this entry
     *                                          (false for the superseded half of an upsert)
     */
    private function releaseEntry(string $className, PersistedEntry $entry, array $candidates, bool $unlink): void
    {
        // A bucket pointing at a freed clone would be walked at request shutdown
        foreach ($candidates as $candidate) {
            $this->unregister($candidate->address);
        }

        if ($unlink) {
            $this->registry->removeEntry($className, $entry);
        } else {
            $this->registry->discardEntry($entry);
        }

        foreach ($entry->members as $address) {
            $member = $this->registry->findObject($address);
            if ($member !== null && $this->registry->adjustShares($address, -1) === 0) {
                $this->registry->removeObject($member);
            }
        }
    }

    /**
     * Registers every member of a freshly persisted graph and caches its root instance
     *
     * Members that already hold a handle this request are skipped: attach() registered
     * them, or they are shared with an entry that did. Re-registering would leak an
     * object-store slot and re-pinning would clobber a live alias's refcount.
     */
    private function materialize(string $name, PersistedEntry $entry): object
    {
        foreach ($entry->members as $address) {
            if (isset($this->handles[$address])) {
                continue;
            }
            $object = $this->registry->findObject($address);
            \assert($object !== null);

            $this->register($address, $object->object);
        }

        $instance               = self::instanceOf($this->rootObjectOf($entry));
        $this->instances[$name] = $instance;

        return $instance;
    }

    /**
     * Gives one persistent clone a fresh object-store handle for this request
     */
    private function register(int $address, CData $object): void
    {
        $this->handles[$address] = Core::$executor->objectStore->put($object);
        $object->gc->refcount    = PersistentObjectFactory::PIN_BASELINE;
    }

    /**
     * Returns an object's request handle to the store's free list (no-op if unregistered)
     *
     * Called before the clone is freed: a bucket still pointing at released memory would
     * be walked by the engine at request shutdown.
     */
    private function unregister(int $address): void
    {
        if (isset($this->handles[$address])) {
            Core::$executor->objectStore->recycle($this->handles[$address]);
            unset($this->handles[$address]);
        }
    }

    /**
     * Resolves the zend_object* of an entry's root member
     */
    private function rootObjectOf(PersistedEntry $entry): CData
    {
        $root = $this->registry->findObject($entry->root());
        if ($root === null) {
            throw new \RuntimeException('Persistent registry is inconsistent: an entry lost its root object');
        }

        return $root->object;
    }

    /**
     * Materializes the PHP instance of a persistent clone (+1 ref held by the return value)
     */
    private static function instanceOf(CData $object): object
    {
        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $object[0]);
        $value->getNativeValue($instance);
        $value->release();

        return $instance;
    }

    /**
     * Rebinds one persisted object to this request's class entry, guarding layout drift
     */
    private function rebindClassEntry(PersistedObject $object): void
    {
        $classValue = Core::$executor->classTable->find(strtolower($object->className));
        if ($classValue === null) {
            throw new \RuntimeException(
                "Class {$object->className} is not loaded; load or preload it before attach()",
            );
        }
        $classEntry = $classValue->getRawClass();

        $signature = Persister::computeSignature($classEntry);
        if ($signature !== $object->signature) {
            throw new \RuntimeException(
                "Class {$object->className} changed its property layout since the object was persisted; " .
                'drop the persisted entry or restart the worker',
            );
        }

        $object->object->ce = $classEntry;
    }

    /**
     * Rolls the inline property slots of one persisted object back to its frozen snapshot
     *
     * A persisted slot is refcounted only when it points at another persistent object, so
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
        $zvalSize       = Core::sizeof(Core::type('zval'));
        $tableBase      = Core::cast('zval *', Core::addr($object->properties_table[0]));
        $frozen         = Core::cast('zval *', $snapshot);
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
