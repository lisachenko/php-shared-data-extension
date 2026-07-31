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
 * A graph is stored as parallel per-index lists in discovery order; index 0 is always
 * the root - the object persist()/get() hands back. Every other index is an object
 * reachable from the root through property slots (directly or through arrays), each
 * with its own snapshot, class name and layout signature: a graph may mix classes.
 *
 * All CData here points into persistent (malloc) memory and stays valid across
 * requests; only the class-entry binding of each object must be refreshed per request.
 */
final class PersistedEntry
{
    /**
     * @param list<CData>  $objects    zend_object* of every persistent clone, root first
     * @param list<CData>  $snapshots  char* snapshot of each object's properties_table (frozen state)
     * @param list<string> $classNames Fully-qualified class name per object, for per-request ce rebinding
     * @param list<string> $signatures Layout signature per object, guarding against class-shape drift
     */
    public function __construct(
        public array $objects,
        public array $snapshots,
        public array $classNames,
        public array $signatures,
    ) {
    }

    /**
     * Returns the zend_object* of the graph root
     */
    public function root(): CData
    {
        return $this->objects[0];
    }

    /**
     * Returns the number of objects in the graph
     */
    public function count(): int
    {
        return \count($this->objects);
    }
}
