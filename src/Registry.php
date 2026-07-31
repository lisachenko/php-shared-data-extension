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
 * Persistent name => object metadata store, anchored in the module globals
 *
 * Layout (everything in persistent memory, valid across requests):
 *
 *   root table:  name (interned) => IS_PTR to a per-entry metadata table
 *   entry table: 'root'      => IS_PTR  zend_object* of the persistent clone
 *                'snapshot'  => IS_PTR  char* frozen properties_table image
 *                'class'     => IS_STRING interned class name
 *                'signature' => IS_STRING interned layout signature
 *
 * The root and entry tables stay mutable (they are registries, not user data);
 * only converted user payloads are sealed immutable by the Persister.
 */
final class Registry
{
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
        $meta = PersistentHashTable::create();

        $this->addPointer($meta, 'root', $entry->object);
        $this->addPointer($meta, 'snapshot', $entry->snapshot);
        $this->addInternedString($meta, 'class', $entry->className);
        $this->addInternedString($meta, 'signature', $entry->signature);

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

        $meta->find('class')->getNativeValue($className);
        $meta->find('signature')->getNativeValue($signature);

        return new PersistedEntry(
            Core::cast('zend_object *', $meta->find('root')->getRawPointer()),
            Core::cast('char *', $meta->find('snapshot')->getRawPointer()),
            $className,
            $signature,
        );
    }

    /**
     * Upserts an IS_PTR zval built by hand: newEntry() cannot wrap bare pointers
     * (an 8-byte pointer CData cannot be cast to a 16-byte zval), direct union-member
     * assignment can. The engine copies the temporary container into its bucket.
     */
    private function addPointer(PersistentHashTable $table, string $key, CData $pointer): void
    {
        $container                = Core::new('zval');
        $container->value->ptr    = Core::cast('void *', $pointer);
        $container->u1->type_info = ReflectionValue::IS_PTR;

        $table->add($key, ReflectionValue::fromValueEntry(Core::addr($container)));
    }

    private function addInternedString(PersistentHashTable $table, string $key, string $string): void
    {
        $interned = StringEntry::persistentInterned($string);

        $container                = Core::new('zval');
        $container->value->str    = $interned->getRawValue();
        // Bare IS_STRING: interned payloads are stored without refcounting
        $container->u1->type_info = ReflectionValue::IS_STRING;

        $table->add($key, ReflectionValue::fromValueEntry(Core::addr($container)));
    }
}
