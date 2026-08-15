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
 * The shape a shared MUTABLE graph is exercised with: one slot per contract rule
 *
 * `counter` and `mirror` are written together and read together, which is what makes a
 * half-applied update observable at all; `label` is the string slot whose pointer is swapped;
 * `peer` is the reference slot that may only ever point at another shared object; `sealed`
 * is the array slot that must stay refused.
 */
class MutableCounter
{
    public int $counter = 0;

    public int $mirror = 0;

    public string $label = 'initial';

    public ?string $note = null;

    public float $ratio = 0.0;

    public bool $flag = false;

    public ?MutableCounter $peer = null;

    public array $sealed = ['frozen' => true];

    /**
     * Untyped on purpose: the slot-level refusal of an array payload has to be reachable
     * without the declared-type check answering first
     */
    public mixed $payload = ['boxed' => 1];
}
