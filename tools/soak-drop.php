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
 * Reclamation soak: persist -> attach -> read -> drop, thousands of times over, asserting
 * that BOTH kinds of memory stay flat.
 *
 * tools/soak.php proves that cycling attach()/detach() over a stable registry leaks no
 * REQUEST memory. This one is the honest proof for the other half: drop() must give the
 * PROCESS its persistent memory back. Every cycle mints a complete graph (nested objects,
 * an object inside an array, nested arrays) and drops it again, so each iteration
 * allocates and frees object clones, snapshot buffers, sealed array tables and the whole
 * registry bookkeeping around them.
 *
 * Three gates, all fatal:
 *   - the registry returns to its exact object count after every single drop();
 *   - request memory (memory_get_usage) flat after warm-up, like soak.php - this is what
 *     z-engine's tracked-block registry shows up in, and it only stays flat because every
 *     persistent block this loop allocates is untracked and freed again;
 *   - resident set size (VmRSS) growth per cycle under a fixed budget.
 *
 * About that budget: persistent STRINGS are deliberately never freed (see Reclaimer for
 * why - non-refcounted payloads whose request-side copies cannot be detected), so this
 * loop does have a known, bounded-per-cycle residue. Measured on this payload:
 *
 *   ~4 kB per cycle with reclamation, ~13 kB per cycle with the Reclaimer disabled
 *
 * so a budget of 6 kB per cycle passes the documented string residue and fails hard the
 * moment object clones, snapshots, sealed arrays or registry tables stop being reclaimed.
 *
 * Usage: php -d ffi.enable=1 tools/soak-drop.php [iterations]
 */

use Lisachenko\SharedData\PersistentStore;
use ZEngine\Core;

require __DIR__ . '/../vendor/autoload.php';

Core::init();

class DropLogger
{
    public string $channel = '';

    public array $levels = [];
}

class DropService
{
    public string $dsn = '';

    public ?DropLogger $logger = null;

    public ?DropConfig $owner = null;
}

class DropConfig
{
    public string $env = 'dev';

    public int $bootCount = 0;

    public array $settings = [];

    public array $services = [];

    public ?DropService $primary = null;

    public ?DropService $replica = null;
}

/**
 * Builds a fresh graph out of stable payloads: a config with two services, ONE shared
 * logger (diamond), back-references to the root (cycles) and a service reached through an
 * array property.
 */
function makeGraph(): DropConfig
{
    $logger          = new DropLogger();
    $logger->channel = 'app';
    $logger->levels  = ['debug', 'info', 'error'];

    $config            = new DropConfig();
    $config->env       = 'production';
    $config->bootCount = 1;
    $config->settings  = ['db' => ['host' => 'localhost', 'port' => 5432], 'features' => ['a', 'b', 'c']];

    foreach (['primary', 'replica'] as $role) {
        $service         = new DropService();
        $service->dsn    = "pgsql:host=localhost;role={$role}";
        $service->logger = $logger;
        $service->owner  = $config;
        $config->{$role} = $service;
    }

    $extra           = new DropService();
    $extra->dsn      = 'pgsql:host=localhost;role=archive';
    $extra->logger   = $logger;
    $config->services = ['archive' => $extra];

    return $config;
}

function residentKiloBytes(): int
{
    foreach (file('/proc/self/status') ?: [] as $line) {
        if (str_starts_with($line, 'VmRSS:')) {
            return (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
    }

    return 0;
}

$totalIterations  = (int) ($argv[1] ?? 5_000);
$warmupIterations = max(1, min(500, intdiv($totalIterations, 10)));
$allowedGrowth    = 64 * 1024;  // request bytes after warm-up
$residentBudget   = 6;          // process kB per measured cycle (see the header)

$store = PersistentStore::boot();

// Objects present before the loop: the gate is about what the loop adds
$objectBaseline = $store->objectCount();

$requestBaseline  = null;
$residentBaseline = null;

for ($iteration = 1; $iteration <= $totalIterations; $iteration++) {
    $config = $store->persist(DropConfig::class, makeGraph());

    if ($config->env !== 'production' || $config->settings['db']['port'] !== 5432) {
        fwrite(STDERR, "Persisted state corrupted at iteration {$iteration}\n");
        exit(1);
    }
    if ($config->primary->logger !== $config->replica->logger
        || $config->primary->logger !== $config->services['archive']->logger) {
        fwrite(STDERR, "Shared (diamond) object identity lost at iteration {$iteration}\n");
        exit(1);
    }
    if ($config->primary->owner !== $config) {
        fwrite(STDERR, "Graph cycle broken at iteration {$iteration}\n");
        exit(1);
    }
    unset($config);

    // A full request boundary in the middle: the entry must survive it and still drop
    $store->detach();

    $attached = $store->attach()[DropConfig::class];
    if ($attached->services['archive']->dsn !== 'pgsql:host=localhost;role=archive'
        || $attached->primary->logger->levels[2] !== 'error') {
        fwrite(STDERR, "Re-attached state corrupted at iteration {$iteration}\n");
        exit(1);
    }
    unset($attached);

    if (!$store->drop(DropConfig::class)) {
        fwrite(STDERR, "drop() reported nothing to remove at iteration {$iteration}\n");
        exit(1);
    }
    if ($store->has(DropConfig::class) || $store->objectCount() !== $objectBaseline) {
        fwrite(STDERR, sprintf(
            "Registry did not return to its baseline at iteration %d (%d objects, expected %d)\n",
            $iteration,
            $store->objectCount(),
            $objectBaseline,
        ));
        exit(1);
    }

    if ($iteration === $warmupIterations) {
        gc_collect_cycles();
        $requestBaseline  = memory_get_usage();
        $residentBaseline = residentKiloBytes();
    }
    if ($iteration % 1_000 === 0 && $requestBaseline !== null) {
        gc_collect_cycles();
        printf(
            "iteration %6d: request %+d bytes, resident %+d kB\n",
            $iteration,
            memory_get_usage() - $requestBaseline,
            residentKiloBytes() - $residentBaseline,
        );
    }
}

$store->detach();
gc_collect_cycles();

$measuredCycles = max(1, $totalIterations - $warmupIterations);
$requestDelta   = memory_get_usage() - ($requestBaseline ?? memory_get_usage());
$residentDelta  = residentKiloBytes() - ($residentBaseline ?? residentKiloBytes());
$allowedResiden = $residentBudget * $measuredCycles;

printf(
    "final delta after %d persist/attach/drop cycles: request %+d bytes (allowed %d), " .
    "resident %+d kB over %d measured cycles = %.2f kB/cycle (budget %d)\n",
    $totalIterations,
    $requestDelta,
    $allowedGrowth,
    $residentDelta,
    $measuredCycles,
    $residentDelta / $measuredCycles,
    $residentBudget,
);

if ($requestDelta > $allowedGrowth) {
    fwrite(STDERR, "FAIL: request memory grew beyond the allowed threshold\n");
    exit(1);
}
if ($residentBaseline > 0 && $residentDelta > $allowedResiden) {
    fwrite(STDERR, "FAIL: drop() did not return the process memory it is supposed to reclaim\n");
    exit(1);
}

echo "SOAK-DROP OK\n";
