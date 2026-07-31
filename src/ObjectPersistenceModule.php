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

use ZEngine\EngineExtension\AbstractModule;

/**
 * Persistent engine module anchoring the object registry across requests
 *
 * The module globals hold two machine words that survive the request boundary in the
 * worker process:
 *   [0] pointer to the persistent registry HashTable (0 until first boot)
 *   [1] reserved for a layout/version tag of the registry format
 *
 * This is the same cross-request anchor mechanism as the counter demo in demo.php,
 * reduced to a single pointer slot: everything else persistent hangs off the registry.
 */
final class ObjectPersistenceModule extends AbstractModule
{
    /**
     * Returns the target thread-safe mode for this module
     */
    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    /**
     * Returns the target debug mode for this module
     */
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    /**
     * Persistent module: registered once per process, survives request shutdown
     */
    public static function targetPersistent(): bool
    {
        return true;
    }

    /**
     * Two persistent machine words: registry pointer + reserved version tag
     */
    public static function globalType(): ?string
    {
        return 'uintptr_t[2]';
    }
}
