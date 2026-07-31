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

namespace Lisachenko\SharedData\RequestBoundary;

/**
 * Persisted across real request boundaries by the FastCGI gate (tools/request-boundary)
 */
class BootCounter
{
    public string $marker = '';

    public int $bootedAtRequest = 0;

    public array $settings = [];
}
