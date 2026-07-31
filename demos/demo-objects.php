<?php
/**
 * Persistent objects demo: PHP objects that survive the request boundary
 *
 * @copyright Copyright 2021, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Run it under a worker (RoadRunner/FrankenPHP) or FPM with opcache.preload; under
 * plain CLI every run is one request, so the object is built on every execution.
 */

use Lisachenko\SharedData\PersistentStore;
use ZEngine\Core;

include __DIR__ . '/../vendor/autoload.php';

Core::init();

class AppConfig
{
    public string $env = 'dev';

    public int $bootCount = 0;

    public array $settings = [];
}

$store = PersistentStore::boot();

if (!$store->has('config')) {
    // Expensive one-time initialization: runs ONCE per worker process
    $config            = new AppConfig();
    $config->env       = 'production';
    $config->bootCount = 1;
    $config->settings  = [
        'db'       => ['host' => 'localhost', 'port' => 5432],
        'features' => ['alpha', 'beta'],
    ];

    // The returned instance is the canonical persistent one - keep using it
    $config = $store->persist('config', $config);
    echo "Initialized config for this worker\n";
} else {
    // Later requests in the same worker recover the object instantly
    $config = $store->get('config');
    echo "Recovered config persisted earlier in this worker\n";
}

var_dump($config->env, $config->bootCount, $config->settings['db']);
