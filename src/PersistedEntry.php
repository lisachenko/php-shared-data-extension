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

use FFI\CData;

/**
 * One persisted object as tracked by the registry
 *
 * All CData here points into persistent (malloc) memory and stays valid across
 * requests; only the class-entry binding of the object must be refreshed per request.
 */
final class PersistedEntry
{
    public function __construct(
        /** zend_object* of the persistent clone */
        public CData $object,
        /** char* snapshot buffer of the whole properties_table (frozen state) */
        public CData $snapshot,
        /** Fully-qualified class name for per-request ce rebinding */
        public string $className,
        /** Layout signature guarding against class-shape drift between requests */
        public string $signature,
    ) {
    }
}
