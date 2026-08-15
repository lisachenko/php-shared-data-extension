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
 * ## One error entry per store, deliberately
 *
 * The object is persisted under this class as its storage key, so capturing a second error
 * replaces the first (persist() is an upsert). That fits the shape of the panic path - a
 * worker captures the failure that ended its task and the waiter reads it - and it keeps the
 * arena from filling up with the error graphs of a crash loop. A consumer that needs several
 * live panics at once should copy the three strings out of the object it attached; in arena
 * mode nothing is freed while the family lives (blocks are reclaimed only at teardown), but
 * a superseded object leaves the registry and can no longer be attached by address.
 */
final class SharedError
{
    public string $className = '';

    public string $message = '';

    public string $trace = '';

    /**
     * Moves a Throwable's description into the arena and returns the shared object's address
     *
     * Returns the address rather than the instance on purpose: holding the persistent
     * instance would make the NEXT capture fail, since the store refuses to release a graph
     * the request can still reach.
     *
     * @return int Address of the shared error-info object, for a result slot record
     */
    public static function capture(PersistentStore $store, \Throwable $error): int
    {
        $info            = new self();
        $info->className = $error::class;
        $info->message   = $error->getMessage();
        $info->trace     = $error->getTraceAsString();

        $store->persist(self::class, $info);
        $address = $store->addressOf(self::class);
        \assert($address !== null);

        return $address;
    }
}
