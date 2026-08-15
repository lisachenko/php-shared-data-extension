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

/**
 * The per-process half of a shared object, kept OUT of the shared struct
 *
 * A `zend_object` in the arena is one struct read by several processes, and three of its
 * fields describe the READER rather than the object. Leaving them in shared memory is what
 * the validation sweep measured, and each one fails differently (docs/shared-memory-model.md,
 * §3):
 *
 *  - `handle` collides BY CONSTRUCTION. Forked children inherit one object-store free list,
 *    so two children registering two different objects are handed the very same number, and
 *    each one overwrites the other's handle inside the shared struct. A later detach then
 *    recycles a slot that belongs to somebody else's object;
 *  - `ce` is only fork-stable for classes loaded before the fork; a class first autoloaded
 *    inside one worker lands at an address no sibling can follow;
 *  - `properties` is written by ENGINE C CODE on read-shaped operations (`var_dump()`,
 *    `get_object_vars()`, `json_encode()`, `(array)`, `serialize()`, `debug_zval_dump()`,
 *    `ReflectionObject`) - a request-heap pointer deposited in shared memory, which a
 *    sibling dereferences at its peril.
 *
 * So the shared struct keeps only what is genuinely shareable - `handlers`, which is the
 * process-lifetime `std_object_handlers` global - and everything per-process lives here,
 * keyed by the one identity that means the same thing everywhere: the ARENA ADDRESS.
 *
 * This table is request-scoped by construction (a PHP object owned by the store, which is
 * itself re-booted per request) and process-scoped by nature: a forked child inherits a copy
 * and immediately overwrites it with its own registrations, because the child's object store
 * is not its parent's.
 */
final class SideTable
{
    /**
     * Object-store handle THIS process holds, keyed by arena address
     *
     * @var array<int, int>
     */
    private array $handles = [];

    /**
     * zend_class_entry* THIS process rebound the object to, keyed by arena address
     *
     * @var array<int, CData>
     */
    private array $classEntries = [];

    /**
     * Records one object as registered in this process's object store
     */
    public function put(int $address, int $handle, CData $classEntry): void
    {
        $this->handles[$address]      = $handle;
        $this->classEntries[$address] = $classEntry;
    }

    /**
     * Records the class entry this process bound an object to, without registering it
     */
    public function bindClassEntry(int $address, CData $classEntry): void
    {
        $this->classEntries[$address] = $classEntry;
    }

    public function has(int $address): bool
    {
        return isset($this->handles[$address]);
    }

    /**
     * The object-store handle of this process, or null when this process never registered it
     */
    public function handleOf(int $address): ?int
    {
        return $this->handles[$address] ?? null;
    }

    /**
     * The class entry of THIS process, which is the only one an engine call may be given
     */
    public function classEntryOf(int $address): ?CData
    {
        return $this->classEntries[$address] ?? null;
    }

    /**
     * Addresses this process has registered, in registration order
     *
     * @return list<int>
     */
    public function addresses(): array
    {
        return array_keys($this->handles);
    }

    public function count(): int
    {
        return \count($this->handles);
    }

    /**
     * Forgets one object entirely (used when its clone is about to disappear)
     */
    public function forget(int $address): void
    {
        unset($this->handles[$address], $this->classEntries[$address]);
    }

    /**
     * Drops every registration; the class bindings go with them
     */
    public function clear(): void
    {
        $this->handles      = [];
        $this->classEntries = [];
    }
}
