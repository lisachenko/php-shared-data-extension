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

/**
 * Thrown when an object graph contains a value that cannot be moved to persistent memory
 *
 * The message always names the property path that failed, so the offending value can be
 * located in deep configuration structures.
 */
class NotPersistableException extends \LogicException
{
    public static function forValue(string $path, string $reason): self
    {
        return new self("Value at {$path} can not be persisted: {$reason}");
    }
}
