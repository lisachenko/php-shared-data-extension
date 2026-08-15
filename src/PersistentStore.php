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
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use Lisachenko\SharedData\Shm\ArenaException;
use Lisachenko\SharedData\Shm\ArenaRegistryLayout;
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\TypeOperationException;

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
     * Module the arena-backed mode anchors itself in, kept apart from the default one so
     * globals[0] always means the same thing within a module (a registry address there, an
     * arena base here)
     */
    public const string SHARED_MODULE = 'shared_arena';

    /**
     * What a shared object's `handle` field is set to once this process has registered it
     *
     * The object-store handle is per-process state that happens to sit inside the shared
     * struct, and forked children hand out IDENTICAL handles for different objects, because
     * they inherit one free list (docs/shared-memory-model.md, §3). Every process therefore
     * keeps its own handle in the side table and overwrites the shared field with a value the
     * store can never produce - a saturated uint32 would need four billion live buckets - so
     * that any code trusting the shared field fails loudly instead of recycling a slot that
     * belongs to a sibling's object.
     *
     * The visible consequence: `spl_object_id()` of a shared object returns this number in
     * every process. It is not an identity; PersistentStore::sharedIdOf() is.
     */
    public const int SHARED_HANDLE_SENTINEL = 0xFFFFFFFF;

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
     * The per-process fields of every shared object this request registered, keyed by ADDRESS
     *
     * Keying by address rather than by entry is what keeps a shared object registered exactly
     * once per request, no matter how many entries reach it - and in arena mode it is the only
     * key that means the same thing in two processes at all.
     */
    private SideTable $sideTable;

    /**
     * Mutation handles minted for this request, keyed by address (their slot views are bound
     * once per process, so handing the same handle back is both cheaper and required)
     *
     * @var array<int, SharedObjectHandle>
     */
    private array $mutableHandles = [];

    private bool $attached = false;

    private bool $shutdownArmed = false;

    /**
     * Slots repaired at detach because they held a pointer into some process's private heap
     */
    private int $repairedSlots = 0;

    private function __construct(Registry $registry, private readonly ?ArenaAllocator $allocator = null)
    {
        $this->registry  = $registry;
        $this->persister = new Persister($allocator);
        $this->sideTable = new SideTable();
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
     * Boots a store whose persisted state lives in a FORK-SHARED ARENA
     *
     * The opt-in counterpart of boot(): same API, same frozen semantics, but every block
     * the store mints - registry tables, object clones, snapshots, strings, sealed arrays -
     * comes out of $arena, so a graph persisted here is readable by every process of the
     * worker family at the same addresses. Nothing about the default path changes; the two
     * modes even use different module names, so a worker may run both side by side.
     *
     * Call order matters:
     *
     *  1. the parent maps the arena and boots this store BEFORE forking - the mapping, the
     *     registry tables and their roots-directory entries must exist at fork time;
     *  2. every child boots the same store again to get its own request-scoped view. A
     *     child takes the RECOVERY path (module globals are inherited and non-zero) and
     *     therefore never writes the module globals: that page is copy-on-write, so a write
     *     would silently become private to the child and desynchronize the family.
     *
     * ## The engine state inside a shared object, and where it actually lives
     *
     * Sharing the memory is one thing; sharing the ENGINE STATE inside a `zend_object` is
     * another, and three of its fields are per-process by nature. They are kept in a
     * per-process SideTable, and the shared struct is treated accordingly:
     *
     *  - **`handle`** - the object-store slot. Forked children inherit one free list and hand
     *    out identical handles, so the shared field is overwritten with
     *    SHARED_HANDLE_SENTINEL after every registration and the real handle lives in the side
     *    table. `spl_object_id()` on a shared object is therefore meaningless *by
     *    construction*; sharedIdOf() returns the arena address, which is the identity every
     *    process agrees on;
     *  - **`ce`** - rebound per process at attach and recorded in the side table; the shared
     *    field is advisory. It is only fork-stable for classes loaded BEFORE the fork
     *    (opcache.preload, or simply touching the class), which remains a requirement: a class
     *    first autoloaded inside one worker lands at an address no sibling can follow;
     *  - **`properties`** - the dynamic-property cache, which engine C code writes on
     *    read-shaped operations (`var_dump()`, `get_object_vars()`, `json_encode()`,
     *    `(array)`, `serialize()`, `debug_zval_dump()`, `ReflectionObject`). It is forced NULL
     *    at attach and never dereferenced in arena mode - a non-null value there may be a
     *    pointer into a sibling's request heap. Call scrubProperties() after any of those
     *    operations, or use inspect() which does it for you.
     *
     * ## Frozen by default, mutable on request
     *
     * A graph persisted through persist() keeps frozen semantics: request-time mutations are
     * rolled back at request end. Pass `mutable: true` to opt one graph into SHARED MUTATION -
     * no rollback, and a synchronized write API (mutableHandle()) that takes the object's
     * stripe lock, interns strings into the arena and refuses anything a sibling could not
     * follow.
     *
     * @param Arena                   $arena      Fork-shared arena, created before any fork
     * @param ArenaRegistryLayout|null $layout    Table capacities; only read when the
     *                                            registry is created (the first boot)
     * @param string                  $moduleName Persistent module to anchor the arena in
     */
    public static function bootShared(
        Arena $arena,
        ?ArenaRegistryLayout $layout = null,
        string $moduleName = self::SHARED_MODULE,
    ): self {
        $module = new ObjectPersistenceModule($moduleName);
        if (!$module->isModuleRegistered()) {
            $module->register();
            $module->startup();
        }

        $globals = $module->getGlobals();
        if ($globals === null) {
            throw new \RuntimeException('Persistent module globals are not available');
        }
        $allocator = new ArenaAllocator($arena);

        if ($globals[0] === 0) {
            [$registry, $base] = Registry::createInArena($allocator, $layout);
            // The ONLY globals write of arena mode, and it happens in the process that
            // owns the arena, before any worker exists
            $globals[0] = $base;
            $globals[1] = Registry::LAYOUT_VERSION;
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
            if ($globals[0] !== $arena->baseAddress()) {
                throw ArenaException::foreignArena($globals[0], $arena->baseAddress());
            }
            // The mapping is inherited, not re-created: prove it is still an arena of this
            // layout before any offset inside it is trusted
            $arena->assertIntact();

            $registry = Registry::fromArena($allocator);
        }

        // Arms the last line of defence before any free(): no path of this process may hand a
        // block of THIS mapping back to an allocator, because there is no allocator that owns
        // it - and in a forked child it would be memory the whole family is still reading
        Reclaimer::protect($arena);

        $store = new self($registry, $allocator);

        self::$activeStores[$moduleName] = $store;

        return $store;
    }

    /**
     * The store booted for $moduleName during this request, if any
     *
     * How a module reaches its own state without interpreting its globals: in arena mode
     * globals[0] is an ARENA BASE, and reading it as a registry pointer would dereference
     * the arena header as a hashtable. The live store knows which registry it holds and how
     * it was built, so anything that wants to REPORT on the state (phpinfo(), diagnostics)
     * asks here first.
     */
    public static function activeStore(string $moduleName): ?self
    {
        return self::$activeStores[$moduleName] ?? null;
    }

    /**
     * Storage keys of every graph this store holds
     *
     * @return list<string>
     */
    public function entryNames(): array
    {
        return $this->registry->names();
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
     * ## Opting into shared mutation
     *
     * With `mutable: true` (arena mode only) the graph keeps everything that makes a
     * persistent clone safe - the PIN_BASELINE refcount pin, GC_PERSISTENT|GC_NOT_COLLECTABLE,
     * bare non-refcounted slot payloads and sealed immutable arrays - but gives up FROZEN
     * SEMANTICS: detach() never rolls its slots back, because a memcpy of a request-old
     * snapshot over a table three other workers are writing would destroy their state. Each
     * object is guarded by the stripe mutex its address hashes to, and mutableHandle() is the
     * synchronized way to write it (docs/shared-memory-model.md, §2).
     *
     * The role is recorded in the registry, not in this process, so every worker attaching the
     * same address later applies the same lifecycle. One object cannot belong to both a frozen
     * and a mutable graph - the two lifecycles contradict each other - and such a persist is
     * refused rather than silently resolved.
     *
     * @template T of object
     *
     * @param class-string<T> $className Storage key; the object must be an instance of it
     * @param T               $object
     * @param bool            $mutable   Persist as a SHARED MUTABLE graph (arena mode only)
     *
     * @return T The canonical persistent instance
     */
    public function persist(string $className, object $object, bool $mutable = false): object
    {
        if (!$object instanceof $className) {
            throw new \InvalidArgumentException(sprintf(
                'Storage key %s must name a class or interface of the persisted instance %s',
                $className,
                get_class($object),
            ));
        }
        if ($mutable && $this->allocator === null) {
            throw SharedMutationException::requiresSharedMode($className);
        }
        $this->attach();

        $entry = $this->persister->persistObject(
            $object,
            fn (int $address): ?PersistedObject => $this->registry->findObject($address),
        );

        // Members the graph REACHED instead of creating are already registered with a role of
        // their own; adopting them into the opposite one would change the lifecycle of an
        // object another entry - possibly another process - is relying on
        foreach ($entry->members as $address) {
            $existing = $this->registry->findObject($address);
            if ($existing !== null && $existing->mutable !== $mutable) {
                throw SharedMutationException::modeConflict($className, $existing->className, $mutable);
            }
        }
        foreach ($entry->created as $created) {
            $created->mutable = $mutable;
        }

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
     * Address of an entry's canonical root clone - the eight bytes that travel between workers
     *
     * In arena mode this is an address inside the shared mapping, and it means the very same
     * object in every process of the worker family. Handing it to a sibling over a socket
     * (as a fixed-size record, never a serialized value) and calling attachObject() there is
     * the whole cross-process exchange protocol: no encoding, no copy, one pointer.
     *
     * Handles are NOT a substitute: forked children inherit the same object-store free list
     * and hand out identical handle numbers, so handles collide by construction. The address
     * is the only stable identity across processes.
     *
     * @param class-string $className
     */
    public function addressOf(string $className): ?int
    {
        $entry = $this->registry->findEntry($className);

        return $entry?->root();
    }

    /**
     * Address of an instance, if THIS store's registry is the one that shares it
     *
     * The predicate behind every "may this value cross a worker boundary?" decision: an
     * ordinary request object, a persistent clone minted by another registry and an object
     * whose entry was dropped all answer null, and only a null-free answer is an address a
     * sibling process may follow. Note what is deliberately not used here - the object's
     * handle, which forked children hand out identically for different objects (EPIC #15,
     * correction #4); identity in the shared area is the ARENA ADDRESS and nothing else.
     *
     * @return int|null Address of the shared zend_object, or null when it is not shared
     */
    public function addressOfInstance(object $instance): ?int
    {
        $value = new ReflectionValue($instance);

        try {
            $address = Core::addressOf($value->getRawObject());
        } finally {
            $value->release();
        }

        return $this->registry->findObject($address) !== null ? $address : null;
    }

    /**
     * Materializes the persistent object living at $address for the current request
     *
     * The receiving half of the exchange above: the object is looked up in the registry
     * (which is what proves the address is one of ours), rebound to this process's class
     * entry and registered in this request's object store if it is not already.
     *
     * @param int $address Address obtained from addressOf() in this or another process
     */
    public function attachObject(int $address): object
    {
        $this->attach();

        $object = $this->registry->findObject($address);
        if ($object === null) {
            throw new \RuntimeException(sprintf(
                'No persistent object is registered at address 0x%x; only addresses handed out by ' .
                'addressOf() of a store sharing this registry can be attached',
                $address,
            ));
        }
        if (!$this->sideTable->has($address)) {
            $this->rebindClassEntry($object);
            $this->register($address, $object->object);
        }

        return self::instanceOf($object->object);
    }

    /**
     * The stable cross-process identity of a shared instance: its ARENA ADDRESS
     *
     * `spl_object_id()` cannot play this role and never could. It reads the object-store handle
     * out of the shared struct, which is per-process state: forked children inherit one free
     * list and are handed identical handles for different objects, and this store overwrites
     * the field with SHARED_HANDLE_SENTINEL precisely so that nobody builds identity on it.
     * The address, by contrast, means the same object in every process of the family - it is
     * what travels over a socket, what the registry keys by, and what a sibling attaches.
     *
     * @throws SharedMutationException When the instance is not a shared object of this store
     */
    public function sharedIdOf(object $instance): int
    {
        return $this->addressOfInstance($instance)
            ?? throw SharedMutationException::notShared(get_class($instance));
    }

    /**
     * The synchronized read/write API for one object of a SHARED MUTABLE graph
     *
     * Handles are cached per address for the request: their slot views are bound once per
     * process, and rebinding them per call would allocate inside what is meant to be a hot
     * path (and, worse, invite a CData creation next to a critical section).
     *
     * @param object|int $target The shared instance, or its arena address
     */
    public function mutableHandle(object|int $target): SharedObjectHandle
    {
        $this->attach();

        $address = \is_int($target)
            ? $target
            : ($this->addressOfInstance($target) ?? throw SharedMutationException::notShared(get_class($target)));

        if (isset($this->mutableHandles[$address])) {
            return $this->mutableHandles[$address];
        }

        $object = $this->registry->findObject($address);
        if ($object === null) {
            throw SharedMutationException::notShared(sprintf('object at 0x%x', $address));
        }
        if ($this->allocator === null) {
            throw SharedMutationException::requiresSharedMode($object->className);
        }
        if (!$object->mutable) {
            throw SharedMutationException::notMutable($object->className, $address);
        }
        if (!$this->sideTable->has($address)) {
            $this->rebindClassEntry($object);
            $this->register($address, $object->object);
        }
        $classEntry = $this->sideTable->classEntryOf($address);
        \assert($classEntry !== null);

        return $this->mutableHandles[$address] = new SharedObjectHandle(
            $this,
            $this->allocator,
            $object->object,
            $classEntry,
            $address,
            $object->className,
        );
    }

    /**
     * The object-store handle THIS process holds for a shared object, if it registered it
     *
     * The value the shared struct deliberately no longer carries. Two processes routinely hold
     * different numbers for the same object - and, because they inherit one free list, the
     * same number for different objects - which is the whole reason it lives here.
     */
    public function processHandleOf(int $address): ?int
    {
        return $this->sideTable->handleOf($address);
    }

    /**
     * Whether this store's state lives in a fork-shared arena
     */
    public function isShared(): bool
    {
        return $this->allocator !== null;
    }

    /**
     * Whether the object at this address was persisted as a shared MUTABLE one
     */
    public function isMutable(object|int $target): bool
    {
        $address = \is_int($target) ? $target : $this->addressOfInstance($target);

        return $address !== null && $this->registry->findObject($address)?->mutable === true;
    }

    /**
     * Clears the dynamic-property cache engine C code left inside a shared object
     *
     * `var_dump()`, `get_object_vars()`, `json_encode()`, `(array)`, `serialize()`,
     * `debug_zval_dump()` and `ReflectionObject` all make the engine rebuild the property bag
     * and CACHE it in the object's `properties` field - a pointer into the request heap of
     * whichever process ran the operation, deposited in memory every process reads. A sibling
     * that follows it dereferences foreign memory; this is the one field of a shared object
     * that must never be trusted (docs/shared-memory-model.md, §3).
     *
     * So the pointer is dropped WITHOUT being dereferenced: no refcount is read, no table is
     * destroyed. What that costs is one request-heap table left to the request allocator,
     * which reclaims it at request end anyway; what it buys is that nothing here can ever
     * touch another process's heap. Call it after any of the operations above - or use
     * inspect(), which brackets the call for you.
     *
     * @param object|int $target Shared instance, or its arena address
     *
     * @return bool Whether a cached table was actually found and dropped
     */
    public function scrubProperties(object|int $target): bool
    {
        $address = \is_int($target) ? $target : $this->addressOfInstance($target);
        $object  = $address === null ? null : $this->registry->findObject($address);
        if ($object === null) {
            return false;
        }
        $objectEntry = ObjectEntry::fromCData($object->object);
        if ($objectEntry->getDynamicPropertiesPointer() === null) {
            return false;
        }
        $objectEntry->setDynamicPropertiesPointer(null);

        return true;
    }

    /**
     * Runs an inspection of a shared object and scrubs whatever it cached inside it
     *
     * The safe way to `var_dump()` or `json_encode()` a shared instance: the cache the engine
     * writes is dropped in the same process that caused it, before any sibling can follow the
     * pointer.
     *
     * @template TResult
     *
     * @param callable(object): TResult $reader Receives the attached instance
     *
     * @return TResult
     */
    public function inspect(object|int $target, callable $reader): mixed
    {
        $address  = \is_int($target) ? $target : $this->sharedIdOf($target);
        $instance = $this->attachObject($address);

        try {
            return $reader($instance);
        } finally {
            $this->scrubProperties($address);
        }
    }

    /**
     * Raw value of a shared object's `properties` field: 0 when it is NULL, as it must be
     *
     * Diagnostics only, and deliberately never dereferenced - the point of this accessor is
     * to observe that the field is clean without touching what it may be pointing at.
     */
    public function dynamicPropertiesAddressOf(int $address): int
    {
        $object = $this->registry->findObject($address);
        if ($object === null) {
            return 0;
        }
        $pointer = ObjectEntry::fromCData($object->object)->getDynamicPropertiesPointer();

        return $pointer === null ? 0 : Core::addressOf($pointer);
    }

    /**
     * Number of slots this request repaired at detach because they held foreign pointers
     *
     * A direct `$object->name = 'x'` on a shared mutable object stores a REQUEST-HEAP string
     * pointer in shared memory (see SharedObjectHandle for why the engine gives no hook to
     * prevent it). Such slots are restored from the frozen image at detach instead of being
     * left behind for a sibling to follow; this counter is how a test - or a worker's
     * diagnostics - notices that it happened.
     */
    public function repairedSlotCount(): int
    {
        return $this->repairedSlots;
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
     *
     * ## Role-aware: a shared mutable graph is never rolled back
     *
     * The snapshot rollback IS the frozen semantics, and it is exactly wrong for a graph that
     * opted into sharing: memcpy'ing a request-old image over slots that three other workers
     * are writing would destroy their state with no diagnostic whatsoever - the writes would
     * simply be gone. So a mutable object keeps everything it has, and only slots holding a
     * pointer OUTSIDE the arena are repaired from the frozen image, because those are not
     * shared state at all but the residue of an unsynchronized direct write (see
     * SharedObjectHandle). Frozen graphs, in either mode, roll back byte for byte as before.
     */
    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }

        // Drop our own references first so only foreign references remain in the count
        $this->instances      = [];
        $this->mutableHandles = [];

        // Exactly the objects THIS process registered this request, never the whole
        // registry. In frozen mode the two are the same set (attach() registers every
        // object there is, and a dropped object leaves both). In arena mode they are not:
        // a sibling worker may have persisted objects after this process attached, and
        // those carry a class entry this process never rebound - rolling them back would
        // dereference another process's zend_class_entry pointer.
        $objects = [];
        foreach ($this->sideTable->addresses() as $address) {
            $object = $this->registry->findObject($address);
            if ($object !== null) {
                $objects[] = $object;
            }
        }

        foreach ($objects as $object) {
            if ($object->mutable) {
                $this->repairForeignPayloads($object);
            } else {
                $this->restoreSnapshot($object->object, $object->snapshot);
            }

            $this->releaseDynamicProperties($object);
        }

        // Pins are re-baselined only after EVERY rollback is done: a slot the request
        // pointed at another persistent object is released above, which decrements that
        // object's pin - possibly one that was already processed in this same pass
        foreach ($objects as $object) {
            $object->object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;
        }

        foreach ($this->sideTable->addresses() as $address) {
            $object = $this->registry->findObject($address);
            $handle = $this->sideTable->handleOf($address);
            if ($object !== null && $handle !== null) {
                $this->releaseHandle($object->object, $handle);
            }
        }

        $this->sideTable->clear();
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
            // The alias predicate is a SINGLE-PROCESS instrument and is disabled for shared
            // graphs. A refcount in the arena is written by every worker that ever copied the
            // value, so it saturates above the pin baseline and stays there: a sibling holding
            // an alias would make every drop fail, and a sibling that exited without detaching
            // would make it succeed while its pages are still mapped. It also protects nothing
            // there - an arena block is never handed back (Registry::removeObject), so dropping
            // a shared entry unlinks bookkeeping and frees no memory at all
            if ($this->allocator !== null) {
                continue;
            }
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
            if ($this->sideTable->has($address)) {
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
     *
     * The handle z-engine hands back is PER-PROCESS state, so it goes into the side table -
     * and in arena mode the field inside the shared struct is immediately overwritten with
     * SHARED_HANDLE_SENTINEL. Not writing it at all is not an option: `zend_objects_store_put`
     * writes it, and two children of one parent are handed the SAME number for different
     * objects (they inherit one free list), so whatever is left in there would be a lie for at
     * least one of them. A value the store can never produce makes that lie unusable.
     *
     * The dynamic-property cache is cleared in the same breath, for the same reason a sibling
     * must never dereference it (see scrubProperties()).
     */
    private function register(int $address, CData $object): void
    {
        $objectEntry = ObjectEntry::fromCData($object);
        $handle      = $objectEntry->register();

        $classEntry = $object->ce;
        \assert($classEntry !== null);
        $this->sideTable->put($address, $handle, $classEntry);

        if ($this->allocator !== null) {
            $object->handle = self::SHARED_HANDLE_SENTINEL;
            $objectEntry->setDynamicPropertiesPointer(null);
        }

        $object->gc->refcount = PersistentObjectFactory::PIN_BASELINE;
    }

    /**
     * Returns an object's request handle to the store's free list (no-op if unregistered)
     *
     * Called before the clone is freed: a bucket still pointing at released memory would
     * be walked by the engine at request shutdown.
     */
    private function unregister(int $address): void
    {
        $handle = $this->sideTable->handleOf($address);
        if ($handle === null) {
            return;
        }
        $object = $this->registry->findObject($address);
        if ($object !== null) {
            $this->releaseHandle($object->object, $handle);
        }
        $this->sideTable->forget($address);
        unset($this->mutableHandles[$address]);
    }

    /**
     * Hands one object-store slot back, using the handle THIS process was given
     *
     * z-engine reads the handle out of the object and verifies that the slot really holds this
     * object before recycling it, which is exactly the guard that matters here: the shared
     * field carries a sentinel, so the side-table handle is put back for the duration of the
     * call and the sentinel is restored afterwards. A refusal is not an error - it means the
     * slot was meanwhile reused, and refusing to recycle somebody else's slot is the guard
     * doing its job.
     *
     * That restore is the ONE moment the shared field is not the sentinel, and it is visible
     * to siblings: a process calling spl_object_id() on a shared object while another one is
     * detaching may see that other process's handle instead. The window is a few instructions
     * wide and cannot be locked away - recycling a store slot is an engine call, and engine
     * calls are forbidden under an arena mutex. It is harmless because nothing in this package
     * ever reads that field (every path goes through the side table), and it is the reason the
     * sentinel is documented as "do not trust this field" rather than "this field is always
     * the sentinel". Identity is sharedIdOf(), always.
     */
    private function releaseHandle(CData $object, int $handle): void
    {
        $object->handle = $handle;

        try {
            ObjectEntry::fromCData($object)->unregister();
        } catch (TypeOperationException) {
            // The slot no longer holds this object: leave it alone
        } finally {
            if ($this->allocator !== null) {
                $object->handle = self::SHARED_HANDLE_SENTINEL;
            }
        }
    }

    /**
     * Restores the slots of a mutable object that hold a pointer into somebody's private heap
     *
     * The only rollback a shared mutable graph ever gets, and it is not about freshness: a
     * slot pointing outside the arena is the residue of a direct `$object->prop = 'x'`, where
     * the engine stored a REQUEST-HEAP string, array or object pointer inside shared memory.
     * Leaving it there would hand every sibling - and every later request of this worker - a
     * pointer into memory that is about to be reclaimed. The frozen image is a valid arena
     * payload by construction, so it is what the slot goes back to.
     *
     * Scalars are untouched: they carry no pointer, so an unsynchronized scalar write is
     * merely racy, and racy is what its author asked for.
     */
    private function repairForeignPayloads(PersistedObject $object): void
    {
        $arena = $this->allocator?->arena();
        if ($arena === null) {
            return;
        }
        $count = (int) $object->object->ce->default_properties_count;
        if ($count === 0) {
            return;
        }
        $zvalSize  = Core::sizeof(Core::type('zval'));
        $tableBase = Core::cast('zval *', Core::addr($object->object->properties_table[0]));
        $frozen    = Core::cast('zval *', $object->snapshot);
        $stripe    = $arena->stripeFor($object->address);

        for ($index = 0; $index < $count; $index++) {
            $slot = Core::addr($tableBase[$index]);
            $type = $slot->u1->v->type;
            if (
                $type !== ReflectionValue::IS_STRING
                && $type !== ReflectionValue::IS_ARRAY
                && $type !== ReflectionValue::IS_OBJECT
            ) {
                continue;
            }
            $payload = (int) Core::cast('uint64_t *', $slot)[0];
            if ($arena->contains($payload)) {
                continue;
            }
            // Not every non-arena pointer is foreign: a string the source object had already
            // interned permanently (a compile-time literal, an opcache SHM string) is kept by
            // pointer at persist time and lives in memory every forked process shares
            // identically. The frozen image says which those are - it holds exactly what
            // persist() decided, so a slot still equal to it was never written by anybody
            if ($payload === (int) Core::cast('uint64_t *', Core::addr($frozen[$index]))[0]) {
                continue;
            }

            // Every CData is created BEFORE the lock: allocation under an arena mutex is
            // forbidden, and a critical section here is one 16-byte memcpy
            $live       = Core::cast('char *', $slot);
            $frozenSlot = Core::cast('char *', Core::addr($frozen[$index]));

            $arena->lockStripe($stripe);
            Core::memcpy($live, $frozenSlot, $zvalSize);
            $arena->unlockStripe($stripe);

            $this->repairedSlots++;
        }
    }

    /**
     * Releases (frozen mode) or simply drops (shared mode) the dynamic-property cache
     *
     * In a single-process registry the table was built by THIS request and releasing it is
     * both correct and tidy. In the arena it may have been built by any process of the family,
     * and reading its refcount would already be a dereference of foreign memory - so the
     * pointer is dropped unread, and the request allocator that owns it reclaims it with the
     * request.
     */
    private function releaseDynamicProperties(PersistedObject $object): void
    {
        $objectEntry       = ObjectEntry::fromCData($object->object);
        $dynamicProperties = $objectEntry->getDynamicPropertiesPointer();
        if ($dynamicProperties === null) {
            return;
        }
        if ($this->allocator !== null) {
            $objectEntry->setDynamicPropertiesPointer(null);

            return;
        }

        // Mirrors zend_array_release(): drop our reference, and let the engine dismantle the
        // table through its own allocator at zero
        $gcHeader           = $dynamicProperties->gc;
        $gcHeader->refcount = $gcHeader->refcount - 1;
        if ($gcHeader->refcount === 0) {
            Core::call('rc_dtor_func', Core::cast('zend_refcounted *', $dynamicProperties));
        }
        $objectEntry->setDynamicPropertiesPointer(null);
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

        // The shared field is advisory: it is written because the engine reads it on every
        // property access, and recorded per process because only THIS process's pointer may
        // ever be handed to an engine call (docs/shared-memory-model.md, §3)
        $object->object->ce = $classEntry;
        $this->sideTable->bindClassEntry($object->address, $classEntry);
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
