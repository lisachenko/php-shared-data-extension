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
 * S15 - can four forked children hammer the bump allocator without ever overlapping?
 *
 * The arena cursor is one 64-bit word in shared memory. Four processes moving it at the
 * same time is exactly the situation where a missing lock does NOT crash: it silently
 * hands two workers the same address, and the corruption surfaces much later as a wrong
 * value in somebody else's object. So the spike checks the property directly.
 *
 * Each child allocates BLOCKS_PER_CHILD blocks of a size that varies per iteration,
 * stamps every byte of each block with its own marker byte, and reports the blocks back
 * to the parent over a pipe as fixed-size binary records (address + size, 16 bytes - no
 * serialization). The parent then verifies:
 *
 *  - no two blocks from any two children overlap;
 *  - every block still carries exactly the marker of the child that owns it, so nobody
 *    wrote through anybody else's block;
 *  - the arena watermark accounts for at least the sum of all block sizes.
 *
 * Run: php -d ffi.enable=1 spikes/s15-concurrent-bump-allocation.php
 */

use Lisachenko\SharedData\Shm\Arena;

require __DIR__ . '/../vendor/autoload.php';

const CHILDREN         = 4;
const BLOCKS_PER_CHILD = 2000;

$arena = Arena::create(8 << 20);

/** @var array<int, array{0: resource, 1: int}> $children pid => [read end, marker] */
$children = [];

for ($index = 0; $index < CHILDREN; $index++) {
    $pipe = [];
    if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipe)) {
        fwrite(STDERR, "cannot create a socket pair\n");

        exit(1);
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        socket_close($pipe[0]);
        $marker  = 0x41 + $index;
        $records = '';
        for ($block = 0; $block < BLOCKS_PER_CHILD; $block++) {
            $size    = 8 + ($block % 57);
            $address = $arena->allocate($size, 8);
            $arena->writeBytes($address, str_repeat(\chr($marker), $size));
            $records .= pack('PP', $address, $size);
        }
        socket_write($pipe[1], $records);
        socket_close($pipe[1]);

        exit(0);
    }

    socket_close($pipe[1]);
    $children[$pid] = [$pipe[0], 0x41 + $index];
}

/** @var list<array{0: int, 1: int, 2: int}> $blocks address, size, marker */
$blocks = [];
foreach ($children as $pid => [$socket, $marker]) {
    $payload = '';
    while (($chunk = socket_read($socket, 65536, PHP_BINARY_READ)) !== false && $chunk !== '') {
        $payload .= $chunk;
    }
    socket_close($socket);
    pcntl_waitpid($pid, $status);

    for ($offset = 0; $offset < \strlen($payload); $offset += 16) {
        /** @var array{1: int, 2: int} $record */
        $record   = unpack('Paddress/Psize', substr($payload, $offset, 16));
        $blocks[] = [$record['address'], $record['size'], $marker];
    }
}

printf("blocks reported: %d (expected %d)\n", \count($blocks), CHILDREN * BLOCKS_PER_CHILD);

usort($blocks, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

$overlaps   = 0;
$corrupted  = 0;
$totalBytes = 0;
$previousEnd = 0;
foreach ($blocks as [$address, $size, $marker]) {
    $totalBytes += $size;
    if ($address < $previousEnd) {
        $overlaps++;
    }
    $previousEnd = $address + $size;
    if ($arena->readBytes($address, $size) !== str_repeat(\chr($marker), $size)) {
        $corrupted++;
    }
}

printf("overlapping blocks: %d\n", $overlaps);
printf("blocks with a foreign marker: %d\n", $corrupted);
printf("bytes requested: %d, arena watermark: %d\n", $totalBytes, $arena->watermark());

$failed = \count($blocks) !== CHILDREN * BLOCKS_PER_CHILD
    || $overlaps !== 0
    || $corrupted !== 0
    || $arena->watermark() < $totalBytes;

printf("\nS15 verdict: %s\n", $failed ? 'FAILED' : 'concurrent bump allocation is disjoint and intact');

exit($failed ? 1 : 0);
