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

namespace Lisachenko\SharedData\Ipc;

use Lisachenko\SharedData\Shm\Arena;

/**
 * The 16 bytes every value occupies while it sits in the shared area
 *
 * ```text
 *   0   uint8   tag        one of ValueTag
 *   1   7 bytes padding    always zero, so the tag word reads back as the bare tag
 *   8   uint64  payload    the value, or an arena address (see ValueTag)
 * ```
 *
 * Two aligned words, which is what makes a record cheap to move: a ring slot, an array
 * element and a result slot are all "one record", written with two word stores and nothing
 * else. It is emphatically NOT a zval - the engine's 16 bytes carry a type_info word whose
 * flags mean refcounting, and a shared record must never imply a reference on anything.
 *
 * ## Why every read of a record happens under a lock
 *
 * A 16-byte store is NOT atomic: the concurrency spike measured ~1.3 % of unlocked
 * two-word reads seeing a tag and a payload from different generations (EPIC #15,
 * correction #1). An aligned 8-byte read alone never tears (correction #2), which is why
 * single-word state (a head counter, a waiter entry, a plain AtomicInt load) may be read
 * without the lock - but tag and payload together may not.
 */
final class ValueRecord
{
    public const int SIZE  = 16;
    public const int WORDS = 2;

    private function __construct()
    {
    }

    /**
     * Stores a record; the caller holds the lock guarding $address
     */
    public static function write(Arena $arena, int $address, ValueTag $tag, int $payload): void
    {
        $arena->writeWord($address, $tag->value);
        $arena->writeWord($address + 8, $payload);
    }

    /**
     * Reads the tag word of a record; the caller holds the lock guarding $address
     */
    public static function readTag(Arena $arena, int $address): ValueTag
    {
        return ValueTag::from($arena->readWord($address));
    }

    /**
     * Reads the payload word of a record; the caller holds the lock guarding $address
     */
    public static function readPayload(Arena $arena, int $address): int
    {
        return $arena->readWord($address + 8);
    }
}
