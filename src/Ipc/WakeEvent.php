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
 * The only thing a socket of this package ever carries: 16 fixed bytes of signalling
 *
 * ```text
 *   0   uint8   opcode     WAKE | RESULT | PANIC | CLOSE
 *   1   uint8   tag        ValueTag of the value that became available (0 when irrelevant)
 *   2   uint16  padding    always zero
 *   4   uint32  id         channel id / result slot id the event is about
 *   8   uint64  address    arena ADDRESS of the value, and only when the tag is address-shaped
 * ```
 *
 * The address field is what makes this a pointer rather than a payload: a record whose tag
 * is INT or FLOAT carries a zero there, because the value lives in the shared area and the
 * socket has no business transporting it. The receiver's answer to any event is the same -
 * go and re-read the shared state - which is why losing one is survivable and why an extra
 * one is harmless.
 *
 * ## Level-triggered, so wakeups can be spurious but never lost
 *
 * A waiter registers itself in the structure's waiter table UNDER the structure's lock and
 * re-checks the state in that same critical section. A notifier reads the waiter table after
 * publishing its record. Either the waiter registered before the notifier looked - then it
 * is notified - or it registered afterwards, in which case its own re-check under the lock
 * already sees the published record and it never blocks. Both processes therefore agree
 * without the socket being reliable at all: events are written non-blocking and a full pipe
 * simply drops one, since every blocking loop also polls the state on a bounded timeout.
 */
final class WakeEvent
{
    public const int SIZE = 16;

    public function __construct(
        public readonly WakeOpcode $opcode,
        public readonly int $id = 0,
        public readonly ValueTag $tag = ValueTag::Nil,
        public readonly int $address = 0,
    ) {
    }

    /**
     * Builds the event announcing a settled value, carrying its address only if it has one
     */
    public static function forValue(WakeOpcode $opcode, int $id, ValueTag $tag, int $payload): self
    {
        return new self($opcode, $id, $tag, $tag->isAddress() ? $payload : 0);
    }

    public function toBytes(): string
    {
        return pack('CCvVP', $this->opcode->value, $this->tag->value, 0, $this->id, $this->address);
    }

    /**
     * Parses one record, or null when the bytes are not a record this build understands
     */
    public static function fromBytes(string $bytes): ?self
    {
        if (\strlen($bytes) !== self::SIZE) {
            return null;
        }
        /** @var array{opcode: int, tag: int, pad: int, id: int, address: int} $fields */
        $fields = unpack('Copcode/Ctag/vpad/Vid/Paddress', $bytes);

        $opcode = WakeOpcode::tryFrom($fields['opcode']);
        $tag    = ValueTag::tryFrom($fields['tag']);
        if ($opcode === null || $tag === null) {
            return null;
        }

        return new self($opcode, $fields['id'], $tag, $fields['address']);
    }
}
