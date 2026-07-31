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
 * Persistent name => object graph metadata store, anchored in the module globals
 *
 * Layout v2 (everything in persistent memory, valid across requests):
 *
 *   root table:  name (interned) => IS_PTR to a per-entry metadata table
 *   entry table: 'count'      => IS_LONG number of objects in the persisted graph
 *                'objects'    => IS_PTR  to a graph table: index => IS_PTR zend_object*
 *                'snapshots'  => IS_PTR  to a graph table: index => IS_PTR char*
 *                'classes'    => IS_PTR  to a graph table: index => IS_STRING class name
 *                'signatures' => IS_PTR  to a graph table: index => IS_STRING signature
 *
 * The four graph tables are index-keyed and parallel: index 0 is the graph root (the
 * object handed back by persist()/get()), every other index is an object reachable from
 * it. Layout v1 stored a single object per entry ('root'/'snapshot'/'class'/'signature');
 * the module globals carry LAYOUT_VERSION so a worker that still holds a v1 registry is
 * rejected instead of misread - see PersistentStore::boot().
 *
 * The root, entry and graph tables stay mutable (they are registries, not user data);
 * only converted user payloads are sealed immutable by the Persister.
 */
final class Registry
{
    /**
     * Version tag of the persistent layout described above, stored in module globals[1]
     */
    public const LAYOUT_VERSION = 2;

    public function __construct(private PersistentHashTable $root)
    {
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
        $root = PersistentHashTable::create();

        return [new self($root), Core::addressOf($root->getRawValue())];
    }

    public function store(string $name, PersistedEntry $entry): void
    {
        $objects    = PersistentHashTable::create();
        $snapshots  = PersistentHashTable::create();
        $classes    = PersistentHashTable::create();
        $signatures = PersistentHashTable::create();

        foreach ($entry->objects as $index => $object) {
            $this->addPointer($objects, $index, $object);
            $this->addPointer($snapshots, $index, $entry->snapshots[$index]);
            $this->addInternedString($classes, $index, $entry->classNames[$index]);
            $this->addInternedString($signatures, $index, $entry->signatures[$index]);
        }

        $meta = PersistentHashTable::create();
        $this->addLong($meta, 'count', $entry->count());
        $this->addPointer($meta, 'objects', $objects->getRawValue());
        $this->addPointer($meta, 'snapshots', $snapshots->getRawValue());
        $this->addPointer($meta, 'classes', $classes->getRawValue());
        $this->addPointer($meta, 'signatures', $signatures->getRawValue());

        $this->addPointer($this->root, $name, $meta->getRawValue());
    }

    public function find(string $name): ?PersistedEntry
    {
        $metaValue = $this->root->find($name);
        if ($metaValue === null) {
            return null;
        }

        return $this->hydrate($metaValue);
    }

    /**
     * @return iterable<string, PersistedEntry>
     */
    public function all(): iterable
    {
        foreach ($this->root->getIterator() as $name => $metaValue) {
            yield (string) $name => $this->hydrate($metaValue);
        }
    }

    public function has(string $name): bool
    {
        return $this->root->find($name) !== null;
    }

    /**
     * Returns the names of all persisted objects
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->root->getIterator() as $name => $metaValue) {
            $names[] = (string) $name;
        }

        return $names;
    }

    private function hydrate(ReflectionValue $metaValue): PersistedEntry
    {
        $meta = PersistentHashTable::fromCData(Core::cast('HashTable *', $metaValue->getRawPointer()));
        $meta->find('count')->getNativeValue($count);

        $objectTable    = $this->graphTable($meta, 'objects');
        $snapshotTable  = $this->graphTable($meta, 'snapshots');
        $classTable     = $this->graphTable($meta, 'classes');
        $signatureTable = $this->graphTable($meta, 'signatures');

        $objects    = [];
        $snapshots  = [];
        $classNames = [];
        $signatures = [];
        for ($index = 0; $index < $count; $index++) {
            $classTable->findIndex($index)->getNativeValue($className);
            $signatureTable->findIndex($index)->getNativeValue($signature);

            $objects[]    = Core::cast('zend_object *', $objectTable->findIndex($index)->getRawPointer());
            $snapshots[]  = Core::cast('char *', $snapshotTable->findIndex($index)->getRawPointer());
            $classNames[] = $className;
            $signatures[] = $signature;
        }

        return new PersistedEntry($objects, $snapshots, $classNames, $signatures);
    }

    /**
     * Recovers one of the index-keyed per-graph tables of an entry
     */
    private function graphTable(PersistentHashTable $meta, string $key): PersistentHashTable
    {
        return PersistentHashTable::fromCData(Core::cast('HashTable *', $meta->find($key)->getRawPointer()));
    }

    /**
     * Upserts an IS_PTR zval built by hand: newEntry() cannot wrap bare pointers
     * (an 8-byte pointer CData cannot be cast to a 16-byte zval), direct union-member
     * assignment can. The engine copies the temporary container into its bucket.
     */
    private function addPointer(PersistentHashTable $table, string|int $key, CData $pointer): void
    {
        $container                = Core::new('zval');
        $container->value->ptr    = Core::cast('void *', $pointer);
        $container->u1->type_info = ReflectionValue::IS_PTR;

        $this->addValue($table, $key, $container);
    }

    private function addInternedString(PersistentHashTable $table, string|int $key, string $string): void
    {
        $interned = StringEntry::persistentInterned($string);

        $container                = Core::new('zval');
        $container->value->str    = $interned->getRawValue();
        // Bare IS_STRING: interned payloads are stored without refcounting
        $container->u1->type_info = ReflectionValue::IS_STRING;

        $this->addValue($table, $key, $container);
    }

    private function addLong(PersistentHashTable $table, string|int $key, int $number): void
    {
        $container                = Core::new('zval');
        $container->value->lval   = $number;
        $container->u1->type_info = ReflectionValue::IS_LONG;

        $this->addValue($table, $key, $container);
    }

    /**
     * Stores a hand-built zval container under a string or integer key
     */
    private function addValue(PersistentHashTable $table, string|int $key, CData $container): void
    {
        $value = ReflectionValue::fromValueEntry(Core::addr($container));

        if (\is_int($key)) {
            $table->addIndex($key, $value);
        } else {
            $table->add($key, $value);
        }
    }
}
