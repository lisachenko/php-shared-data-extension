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
 * Building block of the persisted object graphs used in the tests
 *
 * One flexible node class covers every shape the persister must handle: trees
 * (left/right), diamonds (shared), cycles (parent/self) and objects reached through
 * arrays (services).
 */
class GraphNode
{
    public string $name = '';

    public int $counter = 0;

    public array $options = [];

    /** @var array<string, object> Objects reached through an array property */
    public array $services = [];

    public ?GraphNode $left = null;

    public ?GraphNode $right = null;

    public ?GraphNode $shared = null;

    public ?GraphNode $parent = null;

    public function __construct(string $name = '')
    {
        $this->name = $name;
    }
}
