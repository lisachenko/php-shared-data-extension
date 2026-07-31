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
 * FastCGI-served endpoint of the multi-request gate (driven by run.sh)
 *
 * Every request boots the store, reads the persisted BootCounter and reports one
 * machine-parseable line. The driver asserts that within one worker PID the object is
 * initialized exactly once and its frozen state - including a value mutated on every
 * request - is identical on every later request. This is the honest end-to-end proof
 * that objects survive real RINIT/RSHUTDOWN boundaries.
 */

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\RequestBoundary\BootCounter;
use ZEngine\Core;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BootCounter.php';

header('Content-Type: text/plain');

try {
    Core::init();
    $store = PersistentStore::boot();

    $initialized = 0;
    if (!$store->has(BootCounter::class)) {
        $counter                  = new BootCounter();
        $counter->marker          = 'persistent-marker';
        $counter->bootedAtRequest = (int) getmypid();
        $counter->settings        = ['db' => ['host' => 'localhost', 'port' => 5432]];

        $store->persist(BootCounter::class, $counter);
        $initialized = 1;
    }

    $counter = $store->get(BootCounter::class);

    // Captured BEFORE this request's mutation: previous requests' mutations must
    // have been rolled back at their shutdown (frozen semantics)
    $markerAtAttach = $counter->marker;
    $port           = $counter->settings['db']['port'] ?? -1;

    // Mutate every request; must never leak into the next one
    $counter->marker             = 'mutated-by-' . getmypid();
    $counter->settings['db']     = ['host' => 'elsewhere', 'port' => 1];

    printf(
        "RESULT pid=%d init=%d marker=%s port=%d handle=%d\n",
        getmypid(),
        $initialized,
        $markerAtAttach,
        $port,
        spl_object_id($counter),
    );
} catch (\Throwable $failure) {
    printf("ERROR %s: %s\n", get_class($failure), $failure->getMessage());
}
