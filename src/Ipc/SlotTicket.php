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

/**
 * A claim on one result slot: its index and the generation that claim was minted in
 *
 * A ticket is a plain `int` with a documented bit layout, not an object, and the layout is
 * chosen by the **socket**, not by convenience:
 *
 * ```text
 *   bits  0..15   slot index        0 .. 65535
 *   bits 16..31   generation        1 .. 65535 (0 is never a live generation)
 * ```
 *
 * ## Why it has to fit in 32 bits
 *
 * The only thing this package's sockets ever carry is a {@see WakeEvent}: 16 fixed bytes
 * whose `id` field is a **uint32**. A slot id therefore has to survive a round trip through
 * that field, and packing the generation beside the index is what lets a settling worker be
 * told *which* generation of a slot it is answering for rather than merely which slot. The
 * alternative - keeping the generation purely slot-local and checking it only at read time -
 * would leave a zombie worker able to settle a slot that had been recycled underneath it,
 * because nothing it holds would disagree with the slot's own state.
 *
 * Both halves are 16 bits, so the packed value is at most 0xFFFFFFFF and a table may hold at
 * most 65536 slots ({@see self::MAX_SLOTS}); {@see ResultSlotTable::create()} refuses a larger
 * capacity rather than silently truncating a ticket.
 *
 * ## Generation 0 is deliberately impossible
 *
 * A freshly bump-allocated slot starts at generation 1, so a bare slot *index* handed to any
 * verb decodes as generation 0 and fails the generation check instead of quietly addressing
 * generation 1. That turns "somebody passed the wrong integer" into a typed exception, which
 * is the same treatment a genuinely stale handle gets. Generation 0 is also what a **retired**
 * slot is stamped with (see {@see ResultSlotTable}), so nothing can ever address one again.
 */
final class SlotTicket
{
    /**
     * Bits the slot index occupies; the generation takes the 16 above it
     */
    public const int INDEX_BITS = 16;

    public const int INDEX_MASK = (1 << self::INDEX_BITS) - 1;

    /**
     * Slots one table may hold - a hard ceiling of the ticket layout, not a policy
     */
    public const int MAX_SLOTS = 1 << self::INDEX_BITS;

    /**
     * Highest generation a slot can reach; the next release retires the slot instead of wrapping
     */
    public const int MAX_GENERATION = (1 << 16) - 1;

    /**
     * The generation word of a slot that is out of circulation for good
     */
    public const int RETIRED = 0;

    private function __construct()
    {
    }

    /**
     * Packs a claim; both halves are range-checked by the table that mints them
     */
    public static function pack(int $index, int $generation): int
    {
        return ($generation << self::INDEX_BITS) | ($index & self::INDEX_MASK);
    }

    public static function indexOf(int $ticket): int
    {
        return $ticket & self::INDEX_MASK;
    }

    public static function generationOf(int $ticket): int
    {
        return ($ticket >> self::INDEX_BITS) & self::MAX_GENERATION;
    }

    /**
     * How a ticket is spelled in a message: the slot index, then the generation
     */
    public static function describe(int $ticket): string
    {
        return sprintf('#%d/gen%d', self::indexOf($ticket), self::generationOf($ticket));
    }
}
