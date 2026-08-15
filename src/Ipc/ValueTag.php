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
 * What the eight payload bytes of a value record mean
 *
 * The tag is the entire type system of the shared area: a value that crosses a worker
 * boundary is one of these nine shapes and nothing else. Three of them carry no payload at
 * all, two carry the value itself, three carry an ADDRESS inside the arena, and one is the
 * control tag channels use to publish their end of stream.
 *
 * Deliberately absent: any tag that would mean "a byte encoding of a PHP value graph".
 * Serialization is what the arena exists to avoid - see ValueCodec.
 */
enum ValueTag: int
{
    case Nil = 0;

    case True = 1;

    case False = 2;

    /**
     * Payload is the signed 64-bit value itself
     */
    case Int = 3;

    /**
     * Payload is the IEEE-754 bit pattern of the double (pack('d') / unpack('q'))
     */
    case Float = 4;

    /**
     * Payload is the address of an arena-resident, immutable zend_string
     */
    case Str = 5;

    /**
     * Payload is the address of a shared zend_object the registry knows
     */
    case Obj = 6;

    /**
     * Payload is the address of a SharedArray header
     */
    case Arr = 7;

    /**
     * Control record: this end of the stream is finished (no payload)
     */
    case Close = 8;

    /**
     * Whether the payload is an arena address rather than a value
     *
     * The notification plane uses this: an event record may carry the ADDRESS of a value
     * (which is a pointer, not data), never the value itself - see WakeEvent.
     */
    public function isAddress(): bool
    {
        return $this === self::Str || $this === self::Obj || $this === self::Arr;
    }
}
