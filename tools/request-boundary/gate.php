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
 * Every request boots the store, reads the persisted BootCounter GRAPH (a root plus one
 * nested child that points back at the root) and reports one machine-parseable line. The
 * driver asserts that within one worker PID the graph is initialized exactly once and its
 * frozen state - including values mutated on every request, on the root AND on the nested
 * object - is identical on every later request, with the cycle still intact. This is the
 * honest end-to-end proof that object graphs survive real RINIT/RSHUTDOWN boundaries.
 */

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\RequestBoundary\BootCounter;
use Lisachenko\SharedData\RequestBoundary\BootCounterChild;
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

        $child              = new BootCounterChild();
        $child->childMarker = 'child-marker';
        $child->tags        = ['alpha', 'beta'];
        $child->owner       = $counter; // closes a cycle: root -> child -> root
        $counter->child     = $child;

        $store->persist(BootCounter::class, $counter);
        $initialized = 1;
    }

    $counter = $store->get(BootCounter::class);

    // Captured BEFORE this request's mutations: previous requests' mutations must
    // have been rolled back at their shutdown (frozen semantics)
    $markerAtAttach = $counter->marker;
    $port           = $counter->settings['db']['port'] ?? -1;
    $childMarker    = $counter->child->childMarker;
    $cycle          = $counter->child->owner === $counter ? 1 : 0;

    // Mutate every request, root and nested object alike; must never leak into the next one
    $counter->marker              = 'mutated-by-' . getmypid();
    $counter->settings['db']      = ['host' => 'elsewhere', 'port' => 1];
    $counter->child->childMarker  = 'child-mutated-by-' . getmypid();
    $counter->child->tags         = ['replaced'];
    $counter->child               = new BootCounterChild();

    printf(
        "RESULT pid=%d init=%d marker=%s port=%d child=%s cycle=%d handle=%d\n",
        getmypid(),
        $initialized,
        $markerAtAttach,
        $port,
        $childMarker,
        $cycle,
        spl_object_id($counter),
    );
} catch (\Throwable $failure) {
    printf("ERROR %s: %s\n", get_class($failure), $failure->getMessage());
}
