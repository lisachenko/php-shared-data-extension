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
 * A value was sent into a channel that somebody - possibly another process - had closed
 *
 * Closing is a property of the SHARED channel, not of a process's view of it: the flag lives
 * in the arena, so a producer in one worker learns about a consumer's close() the moment it
 * next takes the ring lock. Receiving from a closed channel is never an error - receivers
 * drain what is still buffered and then see the end of stream - but sending into one is,
 * because the value would have no reader.
 */
final class ClosedChannelException extends \RuntimeException
{
    public static function onSend(int $address): self
    {
        return new self(sprintf(
            'The channel at 0x%x is closed; nothing can be sent into it anymore. Closing is shared ' .
            'state - another worker may have closed it - and receivers may still drain the records ' .
            'already in the ring.',
            $address,
        ));
    }

    public static function whileParked(int $address): self
    {
        return new self(sprintf(
            'The channel at 0x%x was closed while this send was waiting for a receiver to take the ' .
            'record; the handoff will not complete.',
            $address,
        ));
    }
}
