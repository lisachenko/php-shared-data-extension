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
 * Why a process is being woken - the first byte of every event record
 *
 * The set is deliberately tiny and closed: a receiver reacts to all four the same way (go
 * and re-read the shared state), so the opcode is diagnostic information and a scheduling
 * hint, never a protocol the correctness of a wait depends on.
 */
enum WakeOpcode: int
{
    /**
     * A structure changed state: a ring became non-empty or non-full, a wait group hit zero
     */
    case Wake = 1;

    /**
     * A result slot was completed with a value
     */
    case Result = 2;

    /**
     * A result slot was completed with an error-info object
     */
    case Panic = 3;

    /**
     * A channel was closed; receivers drain what is left and then see the end of stream
     */
    case Close = 4;
}
