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

use Lisachenko\SharedData\PersistentStore;

/**
 * What crosses a worker boundary when a coroutine throws: an object, not a rendered message
 *
 * A Throwable itself can never be shared - it is an internal class carrying C state, a
 * backtrace of live frames and, usually, a previous exception chain - and serializing it is
 * exactly what this package refuses to do. So the panic path persists a plain three-string
 * object into the arena instead: the class name, the message and the formatted trace, each
 * an arena-resident string. The result slot then carries its ADDRESS, and the waiting
 * process attaches the same object rather than parsing anything.
 *
 * ## One entry per panic
 *
 * Each capture is its own instance graph ({@see PersistentStore::persistInstance()}), so a
 * second panic never supersedes the first: two workers failing near-simultaneously each
 * leave an error a waiter can still attach by the address its own slot carries. The cost is
 * three short strings per panic, held until the family tears down - the ordinary
 * leak-until-teardown economics of the arena, and a panic is not a hot path.
 */
final class SharedError
{
    public string $className = '';

    public string $message = '';

    public string $trace = '';

    /**
     * Moves a Throwable's description into the arena and returns the shared object's address
     *
     * @return int Address of the shared error-info object, for a result slot record
     */
    public static function capture(PersistentStore $store, \Throwable $error): int
    {
        $info            = new self();
        $info->className = $error::class;
        $info->message   = $error->getMessage();
        $info->trace     = $error->getTraceAsString();

        $shared  = $store->persistInstance($info);
        $address = $store->addressOfInstance($shared);
        \assert($address !== null);

        return $address;
    }
}
