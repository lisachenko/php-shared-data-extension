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

/**
 * Persistent-object soak: cycles attach/mutate/detach like a worker would across
 * thousands of requests and asserts request memory stays flat after warm-up.
 * Exits non-zero on growth, so it can act as a CI gate.
 *
 * Usage: php -d ffi.enable=1 tools/soak.php [iterations]
 */

use Lisachenko\SharedData\PersistentStore;
use ZEngine\Core;

require __DIR__ . '/../vendor/autoload.php';

Core::init();

class SoakConfig
{
    public string $env = 'dev';

    public int $bootCount = 0;

    public array $settings = [];
}

$totalIterations  = (int) ($argv[1] ?? 5_000);
$warmupIterations = min(500, intdiv($totalIterations, 10));
$allowedGrowth    = 64 * 1024; // bytes after warm-up

$store = PersistentStore::boot();

$config            = new SoakConfig();
$config->env       = 'production';
$config->bootCount = 1;
$config->settings  = ['db' => ['host' => 'localhost', 'port' => 5432], 'features' => ['a', 'b', 'c']];
$store->persist(SoakConfig::class, $config);
unset($config);

$baseline = null;

for ($iteration = 1; $iteration <= $totalIterations; $iteration++) {
    $store->detach();

    $objects = $store->attach();
    $current = $objects[SoakConfig::class];

    if ($current->env !== 'production' || $current->bootCount !== 1) {
        fwrite(STDERR, "Frozen state corrupted at iteration {$iteration}\n");
        exit(1);
    }
    if ($current->settings['db']['port'] !== 5432 || $current->settings['features'][2] !== 'c') {
        fwrite(STDERR, "Persistent array corrupted at iteration {$iteration}\n");
        exit(1);
    }

    // Mutate everything, including engine paths that build the properties cache
    $current->env       = "mutated-{$iteration}";
    $current->bootCount = $iteration;
    $current->settings  = ['replaced' => $iteration];
    $probe              = get_object_vars($current);
    unset($current, $objects, $probe);

    if ($iteration === $warmupIterations) {
        gc_collect_cycles();
        $baseline = memory_get_usage();
    }
    if ($iteration % 1_000 === 0 && $baseline !== null) {
        gc_collect_cycles();
        printf("iteration %6d: delta %+d bytes\n", $iteration, memory_get_usage() - $baseline);
    }
}

$store->detach();
gc_collect_cycles();
$finalDelta = memory_get_usage() - ($baseline ?? memory_get_usage());

printf("final delta after %d iterations: %+d bytes (allowed %d)\n", $totalIterations, $finalDelta, $allowedGrowth);

if ($finalDelta > $allowedGrowth) {
    fwrite(STDERR, "FAIL: memory grew beyond the allowed threshold\n");
    exit(1);
}

echo "SOAK OK\n";
