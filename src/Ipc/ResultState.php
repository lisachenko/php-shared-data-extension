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
 * The states a result slot can be in
 *
 * PENDING is the zero value on purpose: a freshly allocated slot is kernel-zeroed arena
 * memory, so a slot that was never written reads as pending in every process without
 * anybody having to initialize it.
 *
 * FREE is the recycling state and it exists so that a released slot is never mistaken for a
 * fresh one. A slot on the free list has already had its generation bumped, so a handle from
 * the previous generation fails the generation check long before the state is consulted; the
 * state is what makes a *dump* of the table readable and what lets the allocator assert that
 * the slot it just popped really was free.
 */
enum ResultState: int
{
    case Pending = 0;

    /**
     * The coroutine returned; the slot carries its value record
     */
    case Done = 1;

    /**
     * The coroutine threw; the slot carries the address of a shared error-info object
     */
    case Panic = 2;

    /**
     * Settled, read, released: the slot is on the free list waiting to be handed out again
     */
    case Free = 3;
}
