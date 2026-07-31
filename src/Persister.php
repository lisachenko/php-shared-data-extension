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
 * Deep converter: moves an object's state into persistent (malloc) memory
 *
 * Conversion model (v1, flat objects):
 *  - the object itself becomes a refcount-pinned persistent clone
 *    (PersistentObjectFactory::persistentClone); the source object stays a completely
 *    ordinary request object - the CLONE is the canonical persistent instance;
 *  - scalar property slots (null/bool/int/float) are plain byte copies;
 *  - strings become persistent interned strings stored in NON-refcounted zval slots -
 *    engine copies share the pointer and copy-on-write on mutation. Strings already
 *    flagged IS_STR_PERMANENT (startup interned / opcache SHM) are kept by pointer;
 *  - arrays are rebuilt element-by-element as sealed (immutable) PersistentHashTables,
 *    recursively applying the same rules; array keys are interned persistently;
 *  - everything else is rejected with the property path in the message: nested objects
 *    (v1 limitation), closures, resources, references, internal classes, enums,
 *    objects with dynamic properties, lazy objects, and objects whose handlers are not
 *    the engine's std_object_handlers.
 *
 * After conversion a byte snapshot of the finished properties_table is taken: detach()
 * restores it at request shutdown, which gives persisted objects frozen semantics
 * (request-time mutations do not survive - see README).
 */
final class Persister
{
    public function persistObject(object $source): PersistedEntry
    {
        $this->assertPersistableObject($source);

        $sourceValue = new ReflectionValue($source);
        $rawSource   = $sourceValue->getRawObject();

        if (!PersistentObjectFactory::usesStandardHandlers($rawSource)) {
            $sourceValue->release();
            throw NotPersistableException::forValue(
                '$root',
                'object handlers differ from std_object_handlers (internal or hooked class)',
            );
        }
        if ($rawSource->extra_flags !== 0) {
            $sourceValue->release();
            throw NotPersistableException::forValue('$root', 'object carries engine extra_flags (lazy object?)');
        }

        $clone = PersistentObjectFactory::persistentClone($rawSource);
        $sourceValue->release();

        $className = \get_class($source);
        $signature = self::computeSignature($clone->ce);

        // Convert every inline property slot of the CLONE in place; the source object
        // is never touched
        $propertyNames = self::declaredPropertyNames($clone->ce);
        $tableBase     = Core::cast('zval *', Core::addr($clone->properties_table[0]));
        foreach ($propertyNames as $index => $propertyName) {
            $this->persistValueInPlace(Core::addr($tableBase[$index]), '$root::$' . $propertyName);
        }

        // Frozen-state snapshot of the finished persistent properties_table
        $tableSize = \count($propertyNames) * Core::sizeof(Core::type('zval'));
        if ($tableSize > 0) {
            $snapshot = Core::trackedNew("char[{$tableSize}]", true);
            Core::memcpy($snapshot, Core::cast('char *', $tableBase), $tableSize);
        } else {
            // Even a property-less object needs a non-null anchor buffer
            $snapshot = Core::trackedNew('char[1]', true);
        }

        return new PersistedEntry($clone, Core::cast('char *', $snapshot), $className, $signature);
    }

    /**
     * Rejects whole categories of objects that can never be made persistent
     */
    private function assertPersistableObject(object $source): void
    {
        $reflection = new \ReflectionObject($source);

        if ($reflection->isInternal()) {
            throw NotPersistableException::forValue('$root', 'internal classes carry C state that cannot be persisted');
        }
        if ($reflection->isEnum()) {
            throw NotPersistableException::forValue('$root', 'enum cases have per-request identity');
        }
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isDefault()) {
                throw NotPersistableException::forValue(
                    '$root::$' . $property->getName(),
                    'dynamic properties are not supported - declare the property on the class',
                );
            }
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
     * Converts one zval slot into a persistent-safe payload, recursing into arrays
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
                throw NotPersistableException::forValue(
                    $path,
                    'nested objects (including closures) are not supported yet - flatten the state or persist them separately',
                );

            case ReflectionValue::IS_RESOURCE:
                throw NotPersistableException::forValue($path, 'resources cannot outlive the request');

            case ReflectionValue::IS_REFERENCE:
                throw NotPersistableException::forValue($path, 'references are not supported yet');

            default:
                throw NotPersistableException::forValue($path, "unsupported zval type {$type}");
        }
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
}
