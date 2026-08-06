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
use ZEngine\Type\PersistentHashTable;
use ZEngine\Type\StringEntry;

/**
 * Persistent registry of named object graphs, anchored in the module globals
 *
 * Layout v3 (everything in persistent memory, valid across requests):
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
 * The split between entries and objects is what v3 is about. Layout v2 stored the whole
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
     */
    public const LAYOUT_VERSION = 3;

    private PersistentHashTable $entries;

    private PersistentHashTable $objects;

    private function __construct(private PersistentHashTable $root)
    {
        $this->entries = self::tableAt($root, 'entries');
        $this->objects = self::tableAt($root, 'objects');
    }

    /**
     * Recovers the registry from a raw pointer stored in module globals
     */
    public static function fromAddress(int $address): self
    {
        // The cast below is a VIEW over this scalar's storage, so it must not be an
        // FFI-owned allocation (the wrapper would dangle once the scalar is collected);
        // request-lifetime memory is exactly right - the registry is re-recovered from
        // module globals on every request anyway
        $rawAddress        = Core::new('uintptr_t', false);
        $rawAddress->cdata = $address;

        return new self(PersistentHashTable::fromCData(Core::cast('HashTable *', $rawAddress)));
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

        $members = new PersistentHashTable();
        foreach ($entry->members as $index => $address) {
            self::addLong($members, $index, $address);
        }

        $meta = new PersistentHashTable();
        self::addLong($meta, 'count', $entry->count());
        self::addPointer($meta, 'members', $members->getRawValue());

        // add() is an upsert: a previous record under this name is simply replaced, which
        // is why PersistentStore hydrates it BEFORE calling store()
        self::addPointer($this->entries, $name, $meta->getRawValue());
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

        Reclaimer::reclaimEntry($entry);
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
        Reclaimer::reclaimEntry($entry);
    }

    /**
     * Drops an object from the global object table and reclaims everything it owns
     *
     * Only legal once the object's share count has reached zero.
     */
    public function removeObject(PersistedObject $object): void
    {
        $this->objects->deleteIndex($object->address);

        Reclaimer::reclaimObject($object);
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
        $arrays = new PersistentHashTable();
        foreach ($object->arrays as $index => $array) {
            self::addPointer($arrays, $index, $array);
        }

        $meta = new PersistentHashTable();
        self::addPointer($meta, 'object', $object->object);
        self::addPointer($meta, 'snapshot', $object->snapshot);
        self::addInternedString($meta, 'class', $object->className);
        self::addInternedString($meta, 'signature', $object->signature);
        self::addLong($meta, 'shares', 0);
        self::addPointer($meta, 'arrays', $arrays->getRawValue());

        $object->shares      = 0;
        $object->metaTable   = $meta->getRawValue();
        $object->arraysTable = $arrays->getRawValue();

        self::addPointer($this->objects, $object->address, $meta->getRawValue());
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
    private static function addPointer(PersistentHashTable $table, string|int $key, CData $pointer): void
    {
        $container                = Core::new('zval');
        $container->value->ptr    = Core::cast('void *', $pointer);
        $container->u1->type_info = ReflectionValue::IS_PTR;

        self::addValue($table, $key, $container);
    }

    private static function addInternedString(PersistentHashTable $table, string|int $key, string $string): void
    {
        $interned = StringEntry::persistentInterned($string);

        $container                = Core::new('zval');
        $container->value->str    = $interned->getRawValue();
        // Bare IS_STRING: interned payloads are stored without refcounting
        $container->u1->type_info = ReflectionValue::IS_STRING;

        self::addValue($table, $key, $container);
    }

    private static function addLong(PersistentHashTable $table, string|int $key, int $number): void
    {
        $container                = Core::new('zval');
        $container->value->lval   = $number;
        $container->u1->type_info = ReflectionValue::IS_LONG;

        self::addValue($table, $key, $container);
    }

    /**
     * Stores a hand-built zval container under a string or integer key
     */
    private static function addValue(PersistentHashTable $table, string|int $key, CData $container): void
    {
        $value = ReflectionValue::fromValueEntry(Core::addr($container));

        if (\is_int($key)) {
            $table->addIndex($key, $value);
        } else {
            $table->add($key, $value);
        }
    }
}
