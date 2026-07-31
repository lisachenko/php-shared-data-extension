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
use ZEngine\EngineExtension\ModuleDependency;
use ZEngine\EngineExtension\ModuleInfoInterface;
use ZEngine\EngineExtension\ModuleLifecycleInterface;

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
 *
 * The module also surfaces its state in phpinfo() (ModuleInfoInterface) and delivers a
 * belt-and-braces detach at request end (ModuleLifecycleInterface::requestShutdown);
 * the primary detach mechanism stays the store's own shutdown function, armed on every
 * attach() - lifecycle trampolines are only wired in the request that registered the
 * module, while the store re-arms itself on every request.
 */
final class ObjectPersistenceModule extends AbstractModule implements ModuleInfoInterface, ModuleLifecycleInterface
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

    /**
     * Everything here lives behind FFI: let the engine enforce the requirement
     *
     * @inheritDoc
     */
    public function getModuleDependencies(): array
    {
        return [ModuleDependency::required('FFI')];
    }

    /**
     * Renders the persisted-object state into this module's phpinfo() section
     *
     * @inheritDoc
     */
    public function getDisplayInfo(): array
    {
        $names = [];
        $globals = $this->getGlobals();
        if ($globals !== null && $globals[0] !== 0) {
            $names = Registry::fromAddress($globals[0])->names();
        }

        return [
            'Persistent objects support' => 'enabled',
            'Persisted objects'          => count($names),
            'Persisted object names'     => $names === [] ? '(none)' : implode(', ', $names),
        ];
    }

    public function moduleStartup(): void
    {
    }

    public function moduleShutdown(): void
    {
    }

    public function requestStartup(): void
    {
    }

    /**
     * Belt-and-braces detach: idempotent, so a store that already detached through its
     * own shutdown function is left untouched
     */
    public function requestShutdown(): void
    {
        PersistentStore::detachActiveStores();
    }
}
