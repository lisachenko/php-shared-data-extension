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
 * What a result slot holds right now: its state, and the value materialized from its record
 *
 * The value is built from the shared record AFTER the slot lock is released, so a SlotResult
 * is an ordinary request-scoped value object. For a PANIC it carries the shared error-info
 * object (see SharedError), which is a real object in the arena and not a rendered message.
 */
final class SlotResult
{
    public function __construct(
        public readonly int $id,
        public readonly ResultState $state,
        public readonly mixed $value = null,
        public readonly ValueTag $tag = ValueTag::Nil,
    ) {
    }

    public function isPending(): bool
    {
        return $this->state === ResultState::Pending;
    }

    public function isDone(): bool
    {
        return $this->state === ResultState::Done;
    }

    public function isPanic(): bool
    {
        return $this->state === ResultState::Panic;
    }
}
