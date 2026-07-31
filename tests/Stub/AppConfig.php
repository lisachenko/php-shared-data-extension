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
 * A typical persistence candidate: scalars, strings and nested arrays, no live handles
 */
class AppConfig
{
    public string $env = 'dev';

    public int $bootCount = 0;

    public float $version = 1.0;

    public bool $debug = false;

    public ?string $label = null;

    public array $settings = [];

    protected string $secret = 'hidden';

    private array $internal = ['a' => 1];

    public function revealSecret(): string
    {
        return $this->secret;
    }

    public function revealInternal(): array
    {
        return $this->internal;
    }
}
