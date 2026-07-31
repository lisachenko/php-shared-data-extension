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
use ZEngine\Type\PersistentHashTable;

/**
 * The single place that gives persistent memory back to the process allocator
 *
 * Everything reclaimed here was allocated by this extension, through one of exactly two
 * malloc-backed paths - z-engine's persistent FFI allocator (object clones, snapshot
 * buffers, hashtable structs) or the engine's own pemalloc(..., 1) for the data block of a
 * GC_PERSISTENT hashtable. That is why raw Core::persistentFree() is correct here and
 * Core::untrackAndFree() is not: the tracked-block registry is a per-request PHP static,
 * while drop() routinely reclaims blocks that were persisted by an EARLIER request. The
 * blocks are untracked as well, so a same-request drop leaves no stale bookkeeping behind.
 *
 * ## What is freed
 *
 * Per object (all provably owned by that object, by construction of the registry layout):
 *   - every sealed array hashtable in the object's allocation list, nested arrays included:
 *     zend_hash_destroy() releases the engine-grown arData through the persistent
 *     allocator (pDestructor is NULL, so stored payloads are never touched), then the
 *     struct itself is freed;
 *   - the frozen properties snapshot buffer;
 *   - the persistent clone block;
 *   - the object's own metadata tables (the record and its allocation list).
 *
 * Per entry: the entry record table and its member-address table.
 *
 * ## What is deliberately LEAKED
 *
 * Persistent strings - property string values, array keys and elements, class names,
 * layout signatures and the registry's own table keys - are NOT freed.
 *
 * They are minted by z-engine's StringEntry::persistentInterned(), which produces a
 * malloc-backed zend_string carrying GC_IMMUTABLE so the engine treats it exactly like an
 * interned string: zvals hold it WITHOUT refcounting and copy-on-write instead of mutating
 * it. That is what makes a persisted string safe to read from userland, and it is also
 * exactly what makes it unsafe to free: a request-side copy (`$name = $config->name;`, an
 * array key built from a persisted value, a string handed to a framework) shares the
 * pointer and leaves no refcount trace behind, so nothing can tell whether the block is
 * still reachable. Object aliases are detectable through the refcount pin; string aliases
 * are not, and drop() must never trade a bounded leak for a use-after-free.
 *
 * The leak is therefore real and proportional to the number of strings persisted over the
 * process lifetime - the strings are NOT deduplicated (persistentInterned() never
 * registers them in the engine's interned-strings tables, so two persists of the same
 * content mint two blocks). Measured on tools/soak-drop.php: about 4 kB per
 * persist/attach/drop cycle of a five-object graph, against roughly 13 kB per cycle if
 * nothing were reclaimed at all. Reclaiming strings safely needs a different string model
 * (a real persistent intern table with content-keyed reuse, so blocks are shared and never
 * individually owned) - the obvious next iteration, tracked in the README.
 *
 * ## Contract for the caller
 *
 * Nothing may reference a reclaimed block afterwards. Object aliases are checked by
 * PersistentStore::drop() through the refcount pin before anything is freed; array
 * payloads cannot be checked that way (immutable arrays live in NON-refcounted zvals, so
 * copies leave no trace), which is why the README states that copies of a dropped entry's
 * arrays taken earlier in the same request must not be used after drop() returns.
 */
final class Reclaimer
{
    /**
     * Frees one persistent object with everything it exclusively owns
     *
     * Only ever called for an object whose share count has reached zero: no registry entry
     * references it anymore, and PersistentStore has verified that no request-visible alias
     * holds it either.
     */
    public static function reclaimObject(PersistedObject $object): void
    {
        foreach ($object->arrays as $array) {
            self::destroyTable($array);
        }
        if ($object->arraysTable !== null) {
            self::destroyTable($object->arraysTable);
        }
        if ($object->metaTable !== null) {
            self::destroyTable($object->metaTable);
        }

        self::freeBlock($object->snapshot);
        self::freeBlock(Core::cast('char *', $object->object));
    }

    /**
     * Frees the persistent bookkeeping of one registry entry
     *
     * The member objects are NOT touched here: they are shared property, released one by
     * one through reclaimObject() once their share count drops to zero.
     */
    public static function reclaimEntry(PersistedEntry $entry): void
    {
        if ($entry->membersTable !== null) {
            self::destroyTable($entry->membersTable);
        }
        if ($entry->metaTable !== null) {
            self::destroyTable($entry->metaTable);
        }
    }

    /**
     * Dismantles a persistent hashtable: engine data block first, then the struct
     */
    private static function destroyTable(CData $table): void
    {
        PersistentHashTable::fromCData($table)->destroy();
    }

    /**
     * Releases a raw persistent buffer (clone block, snapshot image)
     */
    private static function freeBlock(CData $pointer): void
    {
        // Blocks persisted by an earlier request are not in the registry anymore, blocks
        // from THIS request are - and a stale entry pointing at freed memory would let a
        // later untrackAndFree() free a recycled address a second time
        Core::untrack($pointer);
        Core::persistentFree($pointer);
    }
}
