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
use ZEngine\Type\PersistentObjectFactory;
use ZEngine\Type\StringEntry;

/**
 * Deep converter: moves an object graph's state into persistent (malloc) memory
 *
 * Conversion model (v2, object graphs):
 *  - every object of the graph becomes a refcount-pinned persistent clone
 *    (PersistentObjectFactory::persistentClone); the source objects stay completely
 *    ordinary request objects - the CLONES are the canonical persistent instances;
 *  - the walk is keyed by the SOURCE zend_object address, so an object reached twice
 *    is converted once: diamonds keep their shared identity and cycles terminate.
 *    Every clone is registered in that map BEFORE its own slots are converted, which
 *    is what makes a self-reference resolve to the clone instead of recursing forever;
 *  - nested-object slots keep their refcounted IS_OBJECT_EX type_info and are simply
 *    retargeted at the child clone: the byte-copied slot never owned a reference to the
 *    source object, and the clone's refcount pin absorbs request-time addref/delref;
 *  - scalar property slots (null/bool/int/float) are plain byte copies;
 *  - strings become persistent interned strings stored in NON-refcounted zval slots -
 *    engine copies share the pointer and copy-on-write on mutation. Strings already
 *    flagged IS_STR_PERMANENT (startup interned / opcache SHM) are kept by pointer;
 *  - arrays are rebuilt element-by-element as sealed (immutable) PersistentHashTables,
 *    recursively applying the same rules (objects inside arrays join the graph too);
 *    array keys are interned persistently;
 *  - everything else is rejected with the property path in the message: closures,
 *    resources, references, internal classes, enums, objects with dynamic properties,
 *    lazy objects, objects whose handlers are not the engine's std_object_handlers, and
 *    objects that are already persistent (a clone from another persist() call - graphs
 *    do not share objects, persist a single root instead).
 *
 * After conversion a byte snapshot of every finished properties_table is taken: detach()
 * restores them at request shutdown, which gives persisted graphs frozen semantics
 * (request-time mutations do not survive - see README).
 */
final class Persister
{
    /**
     * Cycle/diamond map of the graph walk: source zend_object address => persistent clone
     *
     * @var array<int, CData>
     */
    private array $cloneByAddress = [];

    /** @var list<CData> Persistent clones in discovery order, index 0 = root */
    private array $clones = [];

    /** @var list<string> Class name per clone */
    private array $classNames = [];

    /** @var list<string> Layout signature per clone */
    private array $signatures = [];

    /**
     * Converts the object graph reachable from $source into a persistent graph entry
     */
    public function persistObject(object $source): PersistedEntry
    {
        $this->resetGraphState();

        try {
            $sourceValue = new ReflectionValue($source);
            try {
                $this->persistGraphObject($sourceValue->getRawObject(), $source, '$root');
            } finally {
                $sourceValue->release();
            }

            // Snapshots are taken only after the whole graph is converted: with cycles a
            // slot may still be written while an outer frame is converting its own object
            $snapshots = [];
            foreach ($this->clones as $clone) {
                $snapshots[] = self::snapshotProperties($clone);
            }

            return new PersistedEntry($this->clones, $snapshots, $this->classNames, $this->signatures);
        } finally {
            $this->resetGraphState();
        }
    }

    /**
     * Computes the class-layout signature used to detect shape drift between requests
     */
    public static function computeSignature(CData $classType): string
    {
        $parts = [
            StringEntry::fromCData($classType->name)->getStringValue(),
            (string) $classType->default_properties_count,
            ...self::declaredPropertyNames($classType),
        ];

        return sha1(implode('|', $parts));
    }

    /**
     * Converts one graph object, or returns the clone minted for it earlier
     *
     * @param CData  $rawObject zend_object* of the SOURCE object (the map key)
     * @param object $instance  PHP instance of the same object, for class-level reflection
     * @param string $path      Property path of this object, for error messages
     *
     * @return CData zend_object* of the persistent clone
     */
    private function persistGraphObject(CData $rawObject, object $instance, string $path): CData
    {
        $address = Core::addressOf($rawObject);
        if (isset($this->cloneByAddress[$address])) {
            // Already seen: diamond edges converge and cycles terminate right here
            return $this->cloneByAddress[$address];
        }

        $this->assertPersistableObject($rawObject, $instance, $path);

        $clone = PersistentObjectFactory::persistentClone($rawObject);

        // Registered BEFORE any slot is converted - a self-reference discovered below
        // must resolve to this very clone instead of recursing forever
        $this->cloneByAddress[$address] = $clone;
        $this->clones[]                 = $clone;
        $this->classNames[]             = \get_class($instance);
        $this->signatures[]             = self::computeSignature($clone->ce);

        // Convert every inline property slot of the CLONE in place; the source object
        // is never touched
        $tableBase = Core::cast('zval *', Core::addr($clone->properties_table[0]));
        foreach (self::declaredPropertyNames($clone->ce) as $index => $propertyName) {
            $this->persistValueInPlace(Core::addr($tableBase[$index]), $path . '::$' . $propertyName);
        }

        return $clone;
    }

    /**
     * Rejects whole categories of objects that can never be made persistent
     */
    private function assertPersistableObject(CData $rawObject, object $instance, string $path): void
    {
        // Checked first: a persistent clone passes the handler check and would otherwise
        // be reported through the extra_flags branch, which explains nothing
        if (($rawObject->gc->u->type_info & Core::engineConstant('GC_PERSISTENT')) !== 0) {
            throw NotPersistableException::forValue(
                $path,
                'object is already persistent - cross-graph sharing of persisted objects is not supported; '
                . 'persist a single graph root',
            );
        }

        $reflection = new \ReflectionObject($instance);
        if ($reflection->isInternal()) {
            throw NotPersistableException::forValue($path, 'internal classes carry C state that cannot be persisted');
        }
        if ($reflection->isEnum()) {
            throw NotPersistableException::forValue($path, 'enum cases have per-request identity');
        }
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isDefault()) {
                throw NotPersistableException::forValue(
                    $path . '::$' . $property->getName(),
                    'dynamic properties are not supported - declare the property on the class',
                );
            }
        }
        if (!PersistentObjectFactory::usesStandardHandlers($rawObject)) {
            throw NotPersistableException::forValue(
                $path,
                'object handlers differ from std_object_handlers (internal or hooked class)',
            );
        }
        if ($rawObject->extra_flags !== 0) {
            throw NotPersistableException::forValue($path, 'object carries engine extra_flags (lazy object?)');
        }
    }

    /**
     * Captures the frozen byte image of an object's finished properties_table
     */
    private static function snapshotProperties(CData $object): CData
    {
        $tableSize = $object->ce->default_properties_count * Core::sizeof(Core::type('zval'));
        if ($tableSize > 0) {
            $snapshot  = Core::trackedNew("char[{$tableSize}]", true);
            $tableBase = Core::cast('char *', Core::addr($object->properties_table[0]));
            Core::memcpy($snapshot, $tableBase, $tableSize);
        } else {
            // Even a property-less object needs a non-null anchor buffer
            $snapshot = Core::trackedNew('char[1]', true);
        }

        return Core::cast('char *', $snapshot);
    }

    /**
     * Returns declared property names indexed by their properties_table slot
     *
     * Slot numbers derive from zend_property_info.offset exactly like the engine's
     * OBJ_PROP_TO_NUM macro: (offset - offsetof(zend_object, properties_table)) / sizeof(zval).
     * Static and virtual (hooked, backing-less) properties own no slot and are skipped.
     *
     * @return array<int, string>
     */
    private static function declaredPropertyNames(CData $classType): array
    {
        $names       = [];
        $infoTable   = Core::addr($classType->properties_info);
        $tableOffset = Core::type('zend_object')->getStructFieldOffset('properties_table');
        $zvalSize    = Core::sizeof(Core::type('zval'));

        $numUsed = $infoTable->nNumUsed;
        for ($index = 0; $index < $numUsed; $index++) {
            $bucket = Core::addr($infoTable->arData[$index]);
            if ($bucket->val->u1->v->type === ReflectionValue::IS_UNDEF) {
                continue;
            }
            $info = Core::cast('zend_property_info *', $bucket->val->value->ptr);
            if (($info->flags & (Core::ZEND_ACC_STATIC | Core::ZEND_ACC_VIRTUAL)) !== 0) {
                continue;
            }
            $slot         = intdiv($info->offset - $tableOffset, $zvalSize);
            $names[$slot] = StringEntry::fromCData($bucket->key)->getStringValue();
        }
        ksort($names);

        return $names;
    }

    /**
     * Converts one zval slot into a persistent-safe payload, recursing into arrays and objects
     */
    private function persistValueInPlace(CData $slot, string $path): void
    {
        $type = $slot->u1->v->type;

        switch ($type) {
            case ReflectionValue::IS_UNDEF:
            case ReflectionValue::IS_NULL:
            case ReflectionValue::IS_FALSE:
            case ReflectionValue::IS_TRUE:
            case ReflectionValue::IS_LONG:
            case ReflectionValue::IS_DOUBLE:
                return; // byte copy is already correct

            case ReflectionValue::IS_STRING:
                $this->persistStringSlot($slot, $path);

                return;

            case ReflectionValue::IS_ARRAY:
                $sealed           = $this->persistArray($slot->value->arr, $path);
                $slot->value->arr = $sealed->getRawValue();
                // Bare IS_ARRAY type_info: immutable payloads are not refcounted
                $slot->u1->type_info = ReflectionValue::IS_ARRAY;

                return;

            case ReflectionValue::IS_OBJECT:
                $this->persistObjectSlot($slot, $path);

                return;

            case ReflectionValue::IS_RESOURCE:
                throw NotPersistableException::forValue($path, 'resources cannot outlive the request');

            case ReflectionValue::IS_REFERENCE:
                throw NotPersistableException::forValue($path, 'references are not supported yet');

            default:
                throw NotPersistableException::forValue($path, "unsupported zval type {$type}");
        }
    }

    /**
     * Retargets an object slot at the persistent clone of the referenced object
     *
     * The slot lives in a clone's byte-copied properties_table (or in a temporary while an
     * array is rebuilt): it never took a reference on the source object, so retargeting it
     * needs no refcounting. The refcounted IS_OBJECT_EX type_info is kept as-is - request
     * code must be able to copy the value around normally, and the clone's refcount pin
     * absorbs every addref/delref it will ever see.
     */
    private function persistObjectSlot(CData $slot, string $path): void
    {
        $rawObject = $slot->value->obj;
        // A PHP instance is required for class-level reflection; the temporary reference
        // it holds is dropped again when this frame returns
        $instance = self::instanceOfRawObject($rawObject);

        $slot->value->obj = $this->persistGraphObject($rawObject, $instance, $path);
    }

    /**
     * Materializes the PHP instance of a live zend_object (balanced: +1 ref held by the return value)
     */
    private static function instanceOfRawObject(CData $rawObject): object
    {
        $value = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $rawObject[0]);
        $value->getNativeValue($instance);
        $value->release();

        return $instance;
    }

    /**
     * Replaces a string payload with an interned persistent copy (or keeps a permanent one)
     */
    private function persistStringSlot(CData $slot, string $path): void
    {
        $string = StringEntry::fromCData($slot->value->str);

        if (!$string->isPermanent()) {
            $interned         = StringEntry::persistentInterned($string->getStringValue());
            $slot->value->str = $interned->getRawValue();
        }
        // Interned/permanent payloads live in non-refcounted slots (bare IS_STRING)
        $slot->u1->type_info = ReflectionValue::IS_STRING;
    }

    /**
     * Rebuilds an array as a sealed persistent hashtable, recursing into elements
     */
    private function persistArray(CData $sourceArray, string $path): PersistentHashTable
    {
        $target   = PersistentHashTable::create();
        $isPacked = (bool) ($sourceArray->u->flags & Core::engineConstant('HASH_FLAG_PACKED'));
        $numUsed  = $sourceArray->nNumUsed;

        $element = Core::new('zval', false);
        for ($index = 0; $index < $numUsed; $index++) {
            if ($isPacked) {
                $value     = Core::addr($sourceArray->arPacked[$index]);
                $stringKey = null;
                $intKey    = $index;
            } else {
                $bucket    = Core::addr($sourceArray->arData[$index]);
                $value     = Core::addr($bucket->val);
                $stringKey = $bucket->key !== null ? StringEntry::fromCData($bucket->key)->getStringValue() : null;
                $intKey    = $bucket->h;
            }
            if ($value->u1->v->type === ReflectionValue::IS_UNDEF) {
                continue;
            }

            $keyLabel = $stringKey ?? (string) $intKey;
            Core::memcpy($element, $value[0], Core::sizeof(Core::type('zval')));
            $this->persistValueInPlace(Core::addr($element), "{$path}[{$keyLabel}]");

            $elementValue = ReflectionValue::fromValueEntry(Core::addr($element));
            if ($stringKey !== null) {
                $target->add($stringKey, $elementValue);
            } else {
                $target->addIndex($intKey, $elementValue);
            }
        }
        Core::free($element);

        $target->markImmutable();

        return $target;
    }

    /**
     * Drops the per-call graph state (the converter is reusable and re-entrant per call)
     */
    private function resetGraphState(): void
    {
        $this->cloneByAddress = [];
        $this->clones         = [];
        $this->classNames     = [];
        $this->signatures     = [];
    }
}
