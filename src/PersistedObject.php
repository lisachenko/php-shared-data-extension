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
 * One persistent object clone as tracked by the registry's global object table
 *
 * Objects - not entries - are the unit of ownership in layout v3: an object may belong to
 * SEVERAL registry entries at once (cross-graph sharing), so its lifetime is governed by a
 * share count instead of by the entry that first persisted it. Everything needed to bring
 * the clone back to life in a new request, and everything needed to reclaim its memory
 * when the last entry lets go of it, hangs off this record.
 *
 * All CData points into persistent (malloc) memory and stays valid across requests; only
 * the class-entry binding of the clone must be refreshed per request.
 */
final class PersistedObject
{
    /**
     * @param int          $address     Numeric address of the clone - its identity and object-table key
     * @param CData        $object      zend_object* of the persistent clone
     * @param CData        $snapshot    char* frozen byte image of the clone's properties_table
     * @param string       $className   Fully-qualified class name, for per-request ce rebinding
     * @param string       $signature   Layout signature, guarding against class-shape drift
     * @param list<CData>  $arrays      HashTable* of every sealed array this object owns (allocation
     *                                  list for reclamation, nested arrays included)
     * @param int          $shares      Number of registry ENTRIES whose members include this object
     * @param CData|null   $metaTable   HashTable* of the persisted metadata record (null until stored)
     * @param CData|null   $arraysTable HashTable* of the allocation list itself (null until stored)
     */
    public function __construct(
        public int $address,
        public CData $object,
        public CData $snapshot,
        public string $className,
        public string $signature,
        public array $arrays = [],
        public int $shares = 0,
        public ?CData $metaTable = null,
        public ?CData $arraysTable = null,
    ) {
    }
}
