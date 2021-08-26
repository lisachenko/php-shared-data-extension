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

class Extension extends AbstractModule
{
    private static ?string $structureType = null;

    public static function create(string $name, ?string $dataStructure): Extension
    {
        self::$structureType = $dataStructure;
        return new self($name);
    }

    /**
     * Returns the target thread-safe mode for this module
     *
     * Use ZEND_THREAD_SAFE as default if your module does not depend on thread-safe mode.
     */
    public static function targetThreadSafe(): bool
    {
        return ZEND_THREAD_SAFE;
    }

    /**
     * Returns the target debug mode for this module
     *
     * Use ZEND_DEBUG_BUILD as default if your module does not depend on debug mode.
     */
    public static function targetDebug(): bool
    {
        return ZEND_DEBUG_BUILD;
    }

    /**
     * Returns the target API version for this module
     *
     * @see zend_modules.h:ZEND_MODULE_API_NO
     */
    public static function targetApiVersion(): int
    {
        return 20190902;
    }

    /**
     * Returns true if this module should be persistent or false if temporary
     */
    public static function targetPersistent(): bool
    {
        return true;
    }

    /**
     * Returns globals type (if present) or null if module doesn't use global memory
     */
    public static function globalType(): ?string
    {
        return self::$structureType;
    }
}
