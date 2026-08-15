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

namespace Lisachenko\SharedData\Shm;

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Stub\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reporting on an arena-backed module must never interpret its anchor as a registry
 *
 * A module's globals[0] means two different things: a registry hashtable in the default
 * mode, the ARENA BASE in arena mode. phpinfo() walks EVERY registered module, so a worker
 * running both modes had one path where the arena header was read as a hashtable - a
 * segfault, not an exception. The state is reported through the live store instead, and the
 * arena magic is the fallback discriminator when there is no store to ask.
 */
class ArenaModuleInfoTest extends TestCase
{
    private const string MODULE = 'shared_arena_info';

    private static ?PersistentStore $store = null;

    private function store(): PersistentStore
    {
        return self::$store ??= PersistentStore::bootShared(Arena::create(4 << 20), null, self::MODULE);
    }

    public function testPhpinfoReportsAnArenaBackedModuleInsteadOfDereferencingItsAnchor(): void
    {
        $config            = new AppConfig();
        $config->env       = 'arena-info';
        $config->bootCount = 1;
        $config->label     = 'primary';
        $config->settings  = [];

        $this->store()->persist(AppConfig::class, $config);

        ob_start();
        phpinfo(INFO_MODULES);
        $info = (string) ob_get_clean();

        $section = strstr((string) strstr($info, self::MODULE), "\n\n", true);
        $this->assertIsString($section);
        $this->assertStringContainsString('Persistent objects support => enabled', $section);
        $this->assertStringContainsString(AppConfig::class, $section, 'the arena registry was not reported');
    }
}
