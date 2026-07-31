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
 * Distinct storage key for the sharing and drop() tests
 *
 * Entries are keyed by class-string, so two graphs that must coexist (and share objects)
 * need two classes. GraphAlpha and GraphBeta only exist to provide those keys; the node
 * shape they inherit is the one every graph test uses.
 */
class GraphAlpha extends GraphNode
{
}
