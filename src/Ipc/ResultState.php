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
 * The three states a result slot can be in
 *
 * PENDING is the zero value on purpose: a freshly allocated slot is kernel-zeroed arena
 * memory, so a slot that was never written reads as pending in every process without
 * anybody having to initialize it.
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
}
