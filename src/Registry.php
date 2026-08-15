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
use Lisachenko\SharedData\Shm\ArenaAllocator;
use Lisachenko\SharedData\Shm\ArenaException;
use Lisachenko\SharedData\Shm\ArenaRegistryLayout;
use ZEngine\Core;
use ZEngine\Generated\Bucket;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;

/**
 * Persistent registry of named object graphs, anchored in the module globals
 *
 * Layout v4 (everything in persistent memory, valid across requests):
 *
 *   root table:   'entries' => IS_PTR  entry table:  name (interned) => IS_PTR entry record
 *                 'objects' => IS_PTR  object table: clone address (int key) => IS_PTR object record
 *
 *   entry record:  'count'     => IS_LONG   number of member objects
 *                  'members'   => IS_PTR    index => IS_LONG member address, index 0 = root
 *
 *   object record: 'object'    => IS_PTR    zend_object* clone
 *                  'snapshot'  => IS_PTR    char* frozen properties_table image
 *                  'class'     => IS_STRING interned class name
 *                  'signature' => IS_STRING interned layout signature
 *                  'shares'    => IS_LONG   number of entries referencing this object
 *                  'arrays'    => IS_PTR    index => IS_PTR sealed array HashTable*
 *                                           (allocation list owned by this object)
 *
 * ## Where those tables live: process heap, or the fork-shared arena
 *
 * The shape above is the same in both modes; what differs is the ALLOCATOR behind it.
 *
 *  - default (frozen) mode: every table is a malloc-backed PersistentHashTable that grows
 *    on demand, anchored by the address in module globals[0]. Per-worker memory, exactly
 *    as it has always been;
 *  - arena mode (Registry::createInArena): the struct AND the bucket storage of every
 *    table come out of the fork-shared arena, pre-sized once and NEVER grown - the engine
 *    would grow a table by reallocating its data block into the private heap of whichever
 *    worker happened to fill it, so the tables refuse the insert instead (z-engine's
 *    external-storage guard). Module globals[0] then holds the ARENA BASE rather than the
 *    registry address, and the registry tables are found through the arena's own roots
 *    directory, which is the only thing a forked child can rely on.
 *
 * Layout v4 is that second mode: the record shapes are unchanged from v3, but a worker
 * cannot tell from a registry pointer alone whether it is looking at heap tables or at
 * arena tables it must never free, so the version had to move. LAYOUT_VERSION is verified
 * on every boot - see PersistentStore::boot().
 *
 * The split between entries and objects is what v3 was about. Layout v2 stored the whole
 * graph inside its entry (parallel index-keyed tables of objects, snapshots, classes and
 * signatures), which made an object the exclusive property of one entry. Objects now live
 * in ONE process-wide table keyed by the clone's own address, so:
 *
 *  - the persister can look an already-persistent object up and REFERENCE it instead of
 *    rejecting it (cross-graph sharing, see Persister);
 *  - attach() re-registers every persisted object exactly once per request, no matter how
 *    many entries reach it;
 *  - 'shares' tracks how many entries a given object belongs to, which is what makes
 *    drop() able to free memory without ever pulling an object out from under a graph
 *    that still needs it.
 *
 * Layout v1 stored a single object per entry. The module globals carry LAYOUT_VERSION, so
 * a worker still holding a v1/v2 registry is rejected instead of misread - see
 * PersistentStore::boot().
 *
 * All registry tables stay mutable (they are bookkeeping, not user data); only converted
 * user payloads are sealed immutable by the Persister.
 */
final class Registry
{
    /**
     * Version tag of the persistent layout described above, stored in module globals[1]
     *
     * Version history:
     *   v1 - one object per entry
     *   v2 - a whole graph per entry, in parallel index-keyed tables owned by that entry
     *   v3 - entries and objects split into two tables; objects shared between entries
     *   v4 - the same shape, but the tables may live in a fork-shared arena instead of the
     *        process heap (blocks the engine must never grow and this process must never
     *        free), and globals[0] then anchors the ARENA rather than the registry
     */
    public const LAYOUT_VERSION = 4;

    /**
     * Sign correction for nTableMask, which the engine declares unsigned and uses signed
     */
    private const int INT32_MAX    = 0x7FFFFFFF;
    private const int UINT32_RANGE = 0x100000000;

    private PersistentHashTable $entries;

    private PersistentHashTable $objects;

    /**
     * @param ArenaAllocator|null $allocator Source of every table this registry mints; null
     *                                       is the malloc-backed default (frozen mode)
     */
    private function __construct(
        private PersistentHashTable $root,
        private readonly ?ArenaAllocator $allocator = null,
    ) {
        $this->entries = self::tableAt($root, 'entries');
        $this->objects = self::tableAt($root, 'objects');
    }

    /**
     * Recovers the registry from a raw pointer stored in module globals
     */
    public static function fromAddress(int $address): self
    {
        return new self(self::tableAtAddress($address));
    }

    /**
     * Creates a brand-new registry and returns it with its persistent address
     *
     * @return array{0: self, 1: int} Registry plus the address to store in module globals
     */
    public static function create(): array
    {
        $root = new PersistentHashTable();
        self::addPointer($root, 'entries', new PersistentHashTable()->getRawValue());
        self::addPointer($root, 'objects', new PersistentHashTable()->getRawValue());

        return [new self($root), Core::addressOf($root->getRawValue())];
    }

    /**
     * Creates a registry whose tables live in the fork-shared arena
     *
     * Called ONCE, by the process that owns the arena, before any worker is forked. The
     * three tables are pre-sized from $layout and published in the arena's roots directory,
     * which is how a child (or a later request of this same worker) finds them again with
     * nothing but the arena mapping in hand.
     *
     * @return array{0: self, 1: int} Registry plus the ARENA BASE to store in module globals
     */
    public static function createInArena(ArenaAllocator $allocator, ?ArenaRegistryLayout $layout = null): array
    {
        $layout ??= new ArenaRegistryLayout();
        $arena    = $allocator->arena();

        $root    = $allocator->createTable(ArenaRegistryLayout::RECORD_CAPACITY);
        $entries = $allocator->createTable($layout->entryCapacity);
        $objects = $allocator->createTable($layout->objectCapacity);

        self::addPointer($root, 'entries', $entries->getRawValue(), $allocator);
        self::addPointer($root, 'objects', $objects->getRawValue(), $allocator);

        $arena->putRoot(ArenaRegistryLayout::ROOT_TABLE, Core::addressOf($root->getRawValue()));
        $arena->putRoot(ArenaRegistryLayout::ROOT_ENTRIES, Core::addressOf($entries->getRawValue()));
        $arena->putRoot(ArenaRegistryLayout::ROOT_OBJECTS, Core::addressOf($objects->getRawValue()));

        return [new self($root, $allocator), $arena->baseAddress()];
    }

    /**
     * Recovers an arena-resident registry through the arena's roots directory
     *
     * The only recovery path a forked worker has: it inherits the mapping, looks the root
     * table up by name and rebuilds its view over tables it did not create.
     */
    public static function fromArena(ArenaAllocator $allocator): self
    {
        $address  = $allocator->arena()->requireRoot(ArenaRegistryLayout::ROOT_TABLE);
        $registry = new self(self::tableAtAddress($address), $allocator);

        // Recovery is the moment to notice that a previous worker made the engine grow one
        // of these tables: the resize writes the new private-heap block into the SHARED
        // struct before it aborts, so a sibling would otherwise read plausible garbage out
        // of memory that belongs to a process that is already gone
        $registry->assertArenaResident($registry->entries, 'entries');
        $registry->assertArenaResident($registry->objects, 'objects');

        return $registry;
    }

    /**
     * Whether this registry's tables live in the fork-shared arena
     */
    public function isArenaBacked(): bool
    {
        return $this->allocator !== null;
    }

    /**
     * Registers a freshly persisted graph under $name, sharing what is already persisted
     *
     * Order matters: the new entry's members are share-incremented BEFORE the caller
     * releases whatever entry lived under this name before, so an object belonging to both
     * generations never transits through a share count of zero (and is therefore never
     * reclaimed and immediately re-created).
     */
    public function store(string $name, PersistedEntry $entry): void
    {
        foreach ($entry->created as $object) {
            $this->addObject($object);
        }
        foreach ($entry->members as $address) {
            $this->adjustShares($address, +1);
        }

        $members = $this->newTable(\count($entry->members));
        foreach ($entry->members as $index => $address) {
            self::addLong($members, $index, $address, $this->allocator);
        }

        $meta = $this->newTable(ArenaRegistryLayout::RECORD_CAPACITY);
        self::addLong($meta, 'count', $entry->count(), $this->allocator);
        self::addPointer($meta, 'members', $members->getRawValue(), $this->allocator);

        // add() is an upsert: a previous record under this name is simply replaced, which
        // is why PersistentStore hydrates it BEFORE calling store()
        $this->assertRegistryRoom($this->entries, 'entries', $this->entries->find($name) !== null);

        self::addPointer($this->entries, $name, $meta->getRawValue(), $this->allocator);
    }

    /**
     * Removes an entry from the registry and reclaims its own bookkeeping
     *
     * Member objects are untouched here - see PersistentStore::releaseEntry() for the
     * share accounting that decides which of them may actually be freed.
     */
    public function removeEntry(string $name, PersistedEntry $entry): void
    {
        $this->entries->delete($name);

        $this->reclaimEntry($entry);
    }

    /**
     * Reclaims the bookkeeping of an entry that has already been REPLACED under its name
     *
     * store() is an upsert: after it, the name points at the new record and the previous
     * one is unreachable but still allocated. Deleting the name would remove the new
     * record, so the superseded generation is only reclaimed, never unlinked.
     */
    public function discardEntry(PersistedEntry $entry): void
    {
        $this->reclaimEntry($entry);
    }

    /**
     * Drops an object from the global object table and reclaims everything it owns
     *
     * Only legal once the object's share count has reached zero.
     */
    public function removeObject(PersistedObject $object): void
    {
        $this->objects->deleteIndex($object->address);

        // Arena blocks are never given back one by one: the region is reclaimed as a whole
        // when its creating process exits, and a free() through this process's allocator
        // would be a free() of memory the process heap never handed out (leak-until-teardown
        // v1 - see Arena). Heap registries reclaim exactly as they did in v3.
        if ($this->allocator === null) {
            Reclaimer::reclaimObject($object);
        }
    }

    /**
     * Reclaims an entry's bookkeeping, unless the tables belong to the arena
     */
    private function reclaimEntry(PersistedEntry $entry): void
    {
        if ($this->allocator === null) {
            Reclaimer::reclaimEntry($entry);
        }
    }

    /**
     * Adds $delta to an object's share count and returns the new value
     */
    public function adjustShares(int $address, int $delta): int
    {
        $object = $this->findObject($address);
        if ($object === null) {
            throw new \RuntimeException(sprintf(
                'Persistent object at address 0x%x is not registered; the registry is inconsistent',
                $address,
            ));
        }
        $shares = $object->shares + $delta;
        \assert($object->metaTable !== null);

        // Deliberately WITHOUT the arena allocator: 'shares' is always an upsert of a key
        // the record already carries, and the engine keeps the bucket's original key - a
        // fresh arena string per share adjustment would be an unbounded leak for nothing
        self::addLong(PersistentHashTable::fromCData($object->metaTable), 'shares', $shares);

        return $shares;
    }

    public function findEntry(string $name): ?PersistedEntry
    {
        $metaValue = $this->entries->find($name);

        return $metaValue === null ? null : $this->hydrateEntry($metaValue);
    }

    /**
     * @return iterable<string, PersistedEntry>
     */
    public function allEntries(): iterable
    {
        foreach ($this->entries->getIterator() as $name => $metaValue) {
            yield (string) $name => $this->hydrateEntry($metaValue);
        }
    }

    /**
     * Returns the persisted metadata of one object clone, by its address
     */
    public function findObject(int $address): ?PersistedObject
    {
        $metaValue = $this->objects->findIndex($address);

        return $metaValue === null ? null : self::hydrateObject($address, $metaValue);
    }

    /**
     * Walks every persisted object exactly once, regardless of how many entries share it
     *
     * @return iterable<int, PersistedObject>
     */
    public function allObjects(): iterable
    {
        // The object table is integer-keyed, and the engine yields no key for such buckets;
        // the address is recovered from the clone pointer, which is where it came from
        foreach ($this->objects->getIterator() as $metaValue) {
            $object = self::hydrateObject(0, $metaValue);
            $object->address = Core::addressOf($object->object);

            yield $object->address => $object;
        }
    }

    public function has(string $name): bool
    {
        return $this->entries->find($name) !== null;
    }

    /**
     * Returns the names of all persisted graphs
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->entries->getIterator() as $name => $metaValue) {
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * Number of object clones currently held by the registry (shared ones counted once)
     *
     * Reclamation bookkeeping in one number: it grows with every newly persisted object
     * and falls back when drop() releases the last entry referencing one.
     */
    public function objectCount(): int
    {
        return $this->objects->getRawValue()->nNumOfElements;
    }

    /**
     * Writes one object record into the global object table (share count starts at zero)
     */
    private function addObject(PersistedObject $object): void
    {
        $arrays = $this->newTable(\count($object->arrays));
        foreach ($object->arrays as $index => $array) {
            self::addPointer($arrays, $index, $array, $this->allocator);
        }

        $meta = $this->newTable(ArenaRegistryLayout::RECORD_CAPACITY);
        self::addPointer($meta, 'object', $object->object, $this->allocator);
        self::addPointer($meta, 'snapshot', $object->snapshot, $this->allocator);
        self::addInternedString($meta, 'class', $object->className, $this->allocator);
        self::addInternedString($meta, 'signature', $object->signature, $this->allocator);
        self::addLong($meta, 'shares', 0, $this->allocator);
        self::addPointer($meta, 'arrays', $arrays->getRawValue(), $this->allocator);

        $object->shares      = 0;
        $object->metaTable   = $meta->getRawValue();
        $object->arraysTable = $arrays->getRawValue();

        $this->assertRegistryRoom($this->objects, 'objects', $this->objects->findIndex($object->address) !== null);

        self::addPointer($this->objects, $object->address, $meta->getRawValue(), $this->allocator);
    }

    private function hydrateEntry(ReflectionValue $metaValue): PersistedEntry
    {
        $meta = PersistentHashTable::fromCData(Core::cast('HashTable *', $metaValue->getRawPointer()));
        $meta->find('count')->getNativeValue($count);

        $membersTable = self::tableAt($meta, 'members');

        $members = [];
        for ($index = 0; $index < $count; $index++) {
            $membersTable->findIndex($index)->getNativeValue($address);
            $members[] = $address;
        }

        return new PersistedEntry($members, [], $meta->getRawValue(), $membersTable->getRawValue());
    }

    private static function hydrateObject(int $address, ReflectionValue $metaValue): PersistedObject
    {
        $meta = PersistentHashTable::fromCData(Core::cast('HashTable *', $metaValue->getRawPointer()));
        $meta->find('class')->getNativeValue($className);
        $meta->find('signature')->getNativeValue($signature);
        $meta->find('shares')->getNativeValue($shares);

        $arraysTable = self::tableAt($meta, 'arrays');

        $arrays = [];
        foreach ($arraysTable->getIterator() as $arrayValue) {
            $arrays[] = Core::cast('HashTable *', $arrayValue->getRawPointer());
        }

        return new PersistedObject(
            $address,
            Core::cast('zend_object *', $meta->find('object')->getRawPointer()),
            Core::cast('char *', $meta->find('snapshot')->getRawPointer()),
            $className,
            $signature,
            $arrays,
            $shares,
            $meta->getRawValue(),
            $arraysTable->getRawValue(),
        );
    }

    /**
     * Rebuilds a borrowed view over a persistent table living at a raw address
     */
    private static function tableAtAddress(int $address): PersistentHashTable
    {
        // The cast below is a VIEW over this scalar's storage, so it must not be an
        // FFI-owned allocation (the wrapper would dangle once the scalar is collected);
        // request-lifetime memory is exactly right - the registry is re-recovered from
        // module globals on every request anyway
        $rawAddress        = Core::new('uintptr_t', false);
        $rawAddress->cdata = $address;

        return PersistentHashTable::fromCData(Core::cast('HashTable *', $rawAddress));
    }

    /**
     * Refuses an insert that would make the engine grow an ARENA-resident registry table
     *
     * z-engine guards its own external-storage tables, but only while the wrapper that
     * installed the storage is alive: a registry recovered from the arena (a later request,
     * or a forked child) rebuilds BORROWED views over tables it did not create, and such a
     * view knows nothing about the block behind it. So the guard is re-derived from the
     * table itself - the engine resizes exactly when an insert finds every bucket slot used,
     * which is `nNumUsed == nTableSize`, and an upsert of an existing key consumes no slot.
     *
     * A growth here would perealloc() arena memory into the private heap of whichever
     * worker filled the table, silently unsharing the registry; the hard failure is the
     * whole point. Heap registries (frozen mode) grow exactly as they always did.
     *
     * TODO: drop this in favour of a z-engine re-attachment API (a borrowed view that can
     *       adopt the external block it is sitting on) once lisachenko/z-engine#223 offers one.
     */
    private function assertRegistryRoom(PersistentHashTable $table, string $label, bool $isUpsert): void
    {
        if ($this->allocator === null || $isUpsert) {
            return;
        }
        $raw       = $table->getRawValue();
        $used      = (int) $raw->nNumUsed;
        $tableSize = (int) $raw->nTableSize;
        if ($used < $tableSize) {
            return;
        }

        throw ArenaException::registryTableFull($label, $tableSize);
    }

    /**
     * Verifies that a registry table's bucket storage still lives inside the arena
     *
     * The one observable symptom of an engine resize on shared storage: zend_hash grows a
     * table by reallocating HT_GET_DATA_ADDR into the process heap and writes the new
     * address into the shared struct - it does NOT crash there, it crashes (or does not)
     * much later, so the pointer is the only honest evidence. The block address is
     * recovered exactly like the engine's macro does it:
     *
     *   HT_GET_DATA_ADDR(ht) = (char *) ht->arData - HT_HASH_SIZE(ht->nTableMask)
     *   HT_HASH_SIZE(mask)   = -(int32_t) mask * sizeof(uint32_t)
     *
     * nTableMask is declared unsigned but always USED signed (it is -(2 * nTableSize) for
     * an initialized table), which is why it is sign-corrected before the multiplication.
     */
    private function assertArenaResident(PersistentHashTable $table, string $label): void
    {
        if ($this->allocator === null) {
            return;
        }
        $raw    = $table->getRawValue();
        $arData = $raw->arData;
        if ($arData === null) {
            return; // never initialized: no storage to misplace
        }

        $mask = (int) $raw->nTableMask;
        if ($mask > self::INT32_MAX) {
            $mask -= self::UINT32_RANGE;
        }
        $hashSize  = -$mask * 4;
        $dataStart = Core::addressOf($arData) - $hashSize;
        $dataSize  = $hashSize + (int) $raw->nTableSize * Core::sizeOfType(Bucket::class);

        if (!$this->allocator->arena()->contains($dataStart, $dataSize)) {
            throw ArenaException::registryTableRelocated($label, $dataStart);
        }
    }

    /**
     * Mints one registry table, from the arena when this registry is arena-backed
     *
     * Arena tables are pre-sized for $capacity buckets and can never be grown by the
     * engine; heap tables ignore the hint and grow on demand, exactly as in v3.
     */
    private function newTable(int $capacity): PersistentHashTable
    {
        return $this->allocator?->createTable($capacity) ?? new PersistentHashTable();
    }

    /**
     * Recovers a nested persistent table stored as an IS_PTR value under $key
     */
    private static function tableAt(PersistentHashTable $table, string $key): PersistentHashTable
    {
        $value = $table->find($key);
        if ($value === null) {
            throw new \RuntimeException("Persistent registry is missing the '{$key}' table");
        }

        return PersistentHashTable::fromCData(Core::cast('HashTable *', $value->getRawPointer()));
    }

    /**
     * Upserts an IS_PTR zval built by hand: newEntry() cannot wrap bare pointers
     * (an 8-byte pointer CData cannot be cast to a 16-byte zval), direct union-member
     * assignment can. The engine copies the temporary container into its bucket.
     */
    private static function addPointer(
        PersistentHashTable $table,
        string|int $key,
        CData $pointer,
        ?ArenaAllocator $allocator = null,
    ): void {
        $container                = Core::new('zval');
        $container->value->ptr    = Core::cast('void *', $pointer);
        $container->u1->type_info = ReflectionValue::IS_PTR;

        self::addValue($table, $key, $container, $allocator);
    }

    private static function addInternedString(
        PersistentHashTable $table,
        string|int $key,
        string $string,
        ?ArenaAllocator $allocator = null,
    ): void {
        $interned = StringEntry::persistentInterned($string, $allocator);

        $container                = Core::new('zval');
        $container->value->str    = $interned->getRawValue();
        // Bare IS_STRING: interned payloads are stored without refcounting
        $container->u1->type_info = ReflectionValue::IS_STRING;

        self::addValue($table, $key, $container, $allocator);
    }

    private static function addLong(
        PersistentHashTable $table,
        string|int $key,
        int $number,
        ?ArenaAllocator $allocator = null,
    ): void {
        $container                = Core::new('zval');
        $container->value->lval   = $number;
        $container->u1->type_info = ReflectionValue::IS_LONG;

        self::addValue($table, $key, $container, $allocator);
    }

    /**
     * Stores a hand-built zval container under a string or integer key
     */
    private static function addValue(
        PersistentHashTable $table,
        string|int $key,
        CData $container,
        ?ArenaAllocator $allocator = null,
    ): void {
        $value = ReflectionValue::fromValueEntry(Core::addr($container));

        if (\is_int($key)) {
            $table->addIndex($key, $value);
        } elseif ($allocator === null) {
            $table->add($key, $value);
        } else {
            // The KEY has to live in the arena as well: add() would mint a malloc-backed
            // interned string, and a sibling process walking this table would follow that
            // pointer into memory it never allocated
            $table->addInterned(StringEntry::persistentInterned($key, $allocator), $value);
        }
    }
}
