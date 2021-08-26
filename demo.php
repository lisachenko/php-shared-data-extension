<?php
/**
 * Shared data PHP extension
 *
 * @copyright Copyright 2021, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

use Lisachenko\SharedData\Extension;
use ZEngine\Core;

include __DIR__ . '/vendor/autoload.php';

Core::init();

$extension = Extension::create('shared-data', 'unsigned int[10]');
if (!$extension->isModuleRegistered()) {
    $extension->register();
    $extension->startup();
}

$data  = $extension->getGlobals();
$index = mt_rand(0, 9); // If you have several workers, you should use worker pid to avoid race conditions

$data[$index] = $data[$index] + 1; // We are increasing global counter by one

var_dump($data);
