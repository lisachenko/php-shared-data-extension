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
 * One persisted object GRAPH as tracked by the registry
 *
 * An entry is a NAMED VIEW over the registry's global object table: it owns nothing but a
 * list of member addresses in discovery order, index 0 being the root - the object
 * persist()/get() hands back. Every other member is an object reachable from the root
 * through property slots (directly or through arrays).
 *
 * Since layout v3 a member may be shared with other entries (see Persister): the objects
 * themselves are described once, in PersistedObject records, and entries only reference
 * them. That indirection is what makes both cross-graph sharing and safe drop() possible -
 * removing an entry decrements its members' share counts, and only objects that no entry
 * references anymore are actually reclaimed.
 */
final class PersistedEntry
{
    /**
     * @param list<int>            $members      Addresses of every member object, root first
     * @param list<PersistedObject> $created     Objects MINTED by the persist() call that produced
     *                                           this entry (empty for a hydrated entry); shared
     *                                           members are absent - they are already registered
     * @param CData|null           $metaTable    HashTable* of the persisted entry record (hydrated only)
     * @param CData|null           $membersTable HashTable* of the member list (hydrated only)
     */
    public function __construct(
        public array $members,
        public array $created = [],
        public ?CData $metaTable = null,
        public ?CData $membersTable = null,
    ) {
    }

    /**
     * Returns the address of the graph root
     */
    public function root(): int
    {
        return $this->members[0];
    }

    /**
     * Returns the number of objects in the graph
     */
    public function count(): int
    {
        return \count($this->members);
    }
}
