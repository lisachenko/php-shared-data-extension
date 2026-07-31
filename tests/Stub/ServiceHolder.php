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

namespace Lisachenko\SharedData\Stub;

/**
 * A service-like graph member: persistable state plus one slot for a live handle
 */
class ServiceHolder
{
    public string $dsn = '';

    /** Holds whatever the test needs to smuggle into the graph (eg a resource) */
    public mixed $pdo = null;
}
