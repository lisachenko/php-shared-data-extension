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

namespace Lisachenko\SharedData\Ipc;

use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Stub\AppConfig;

/**
 * Futures across processes: a child computes, the parent wakes on an event record and reads
 *
 * This is the runtime model of EPIC #15 in miniature. The child never sends a value anywhere:
 * it writes a 16-byte record into a slot of the shared area and pokes the parent's socket
 * with a fixed event record. The parent, parked on that socket, wakes and reads the value out
 * of shared memory - for an object that means the very same zend_object, at the very same
 * address, in both processes.
 */
class ResultSlotForkTest extends IpcTestCase
{
    private function slots(int $capacity = 64): ResultSlotTable
    {
        return ResultSlotTable::create($this->allocator(), $this->codec(), $this->wake(), $capacity, null);
    }

    public function testAChildCompletesEveryTagKindAndTheParentReadsItFromSharedMemory(): void
    {
        $slots  = $this->slots();
        $arena  = $this->arena();
        $store  = $this->store();
        $report = $arena->allocate(8);
        $arena->writeWord($report, 0);

        $nested    = SharedArray::create($this->allocator(), $this->codec(), 2);
        $nested[0] = 'computed elsewhere';

        /** @var array<string, int> $ids */
        $ids = [];
        foreach (['nil', 'true', 'false', 'int', 'float', 'string', 'object', 'array'] as $kind) {
            $ids[$kind] = $slots->allocateSlot();
        }

        $child = $this->fork(static function () use ($slots, $store, $arena, $report, $ids, $nested): int {
            $slots->complete($ids['nil'], null);
            $slots->complete($ids['true'], true);
            $slots->complete($ids['false'], false);
            $slots->complete($ids['int'], -4242);
            $slots->complete($ids['float'], 2.5);
            $slots->complete($ids['string'], 'a string interned by the child');

            // A brand-new shared object, minted AFTER the fork: nothing about it can reach
            // the parent through copy-on-write
            $config            = new AppConfig();
            $config->env       = 'from-the-child';
            $config->bootCount = 99;
            $config->label     = 'result';
            $config->settings  = ['db' => ['port' => 5432]];

            $shared  = $store->persist(AppConfig::class, $config);
            $address = $store->addressOfInstance($shared);
            \assert($address !== null);

            // The address is published through shared memory, which is the only channel this
            // suite ever uses for one - eight bytes, no encoding
            $arena->writeWord($report, $address);
            $slots->complete($ids['object'], $shared);
            $slots->complete($ids['array'], $nested);

            return self::OK;
        });

        $this->assertNull($slots->await($ids['nil'], 10.0)->value);
        $this->assertTrue($slots->await($ids['true'], 10.0)->value);
        $this->assertFalse($slots->await($ids['false'], 10.0)->value);
        $this->assertSame(-4242, $slots->await($ids['int'], 10.0)->value);
        $this->assertSame(2.5, $slots->await($ids['float'], 10.0)->value);

        $string = $slots->await($ids['string'], 10.0);
        $this->assertSame(ValueTag::Str, $string->tag);
        $this->assertSame('a string interned by the child', $string->value);

        $object = $slots->await($ids['object'], 10.0);
        $this->assertTrue($object->isDone());
        $this->assertSame(ValueTag::Obj, $object->tag);
        $this->assertInstanceOf(AppConfig::class, $object->value);
        $this->assertSame('from-the-child', $object->value->env);
        $this->assertSame(99, $object->value->bootCount);
        $this->assertSame(5432, $object->value->settings['db']['port']);

        // Zero-copy: the parent holds the object at the address the CHILD persisted it at
        $this->assertSame(
            $arena->readWord($report),
            $store->addressOfInstance($object->value),
            'the object was rebuilt instead of shared',
        );

        $array = $slots->await($ids['array'], 10.0);
        $this->assertInstanceOf(SharedArray::class, $array->value);
        $this->assertSame($nested->address(), $array->value->address());
        $this->assertSame('computed elsewhere', $array->value[0]);

        $this->assertSame(self::OK, $this->await($child));
    }

    public function testTheParentParksOnTheSocketAndWakesOnTheEventRecord(): void
    {
        $slots = $this->slots();
        $id    = $slots->allocateSlot();

        $child = $this->fork(static function () use ($slots, $id): int {
            // Long enough that the parent is certainly parked in stream_select() by now
            usleep(300_000);
            $slots->complete($id, 'woken by an event record');

            return self::OK;
        });

        $before = microtime(true);
        $result = $slots->await($id, 10.0);
        $waited = microtime(true) - $before;

        $this->assertTrue($result->isDone());
        $this->assertSame('woken by an event record', $result->value);
        $this->assertGreaterThan(0.2, $waited, 'the await returned before the child could have completed the slot');
        $this->assertLessThan(5.0, $waited, 'the await did not wake on the notification, it timed out');

        $this->assertSame(self::OK, $this->await($child));
    }

    public function testAPanicTravelsAsASharedErrorObjectRatherThanAMessage(): void
    {
        $slots = $this->slots();
        $store = $this->store();
        $id    = $slots->allocateSlot();

        $child = $this->fork(static function () use ($slots, $store, $id): int {
            try {
                throw new \DomainException('the worker could not finish its unit of work');
            } catch (\Throwable $error) {
                // The Throwable itself can never be shared - what travels is a plain
                // three-string object living in the arena
                $slots->completePanic($id, SharedError::capture($store, $error));
            }

            return self::OK;
        });

        $result = $slots->await($id, 10.0);

        $this->assertTrue($result->isPanic());
        $this->assertSame(ValueTag::Obj, $result->tag);
        $this->assertInstanceOf(SharedError::class, $result->value);
        $this->assertSame(\DomainException::class, $result->value->className);
        $this->assertSame('the worker could not finish its unit of work', $result->value->message);
        $this->assertNotSame('', $result->value->trace);

        $this->assertSame(self::OK, $this->await($child));
    }

    public function testASlotSettlesExactlyOnce(): void
    {
        $slots = $this->slots(4);
        $id    = $slots->allocateSlot();
        $slots->complete($id, 1);

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/already completed/');

        $slots->complete($id, 2);
    }

    public function testAPendingSlotIsReportedRatherThanGuessed(): void
    {
        $slots = $this->slots(4);
        $id    = $slots->allocateSlot();

        $result = $slots->readSlot($id);
        $this->assertTrue($result->isPending());
        $this->assertNull($result->value);

        // Awaiting one nobody will ever complete gives up on the deadline, still pending
        $this->assertTrue($slots->await($id, 0.15)->isPending());
    }

    public function testAPreSizedSlotTableRefusesToGrow(): void
    {
        $slots = $this->slots(2);
        $slots->allocateSlot();
        $slots->allocateSlot();

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/pre-sized in the arena and never grows/');

        $slots->allocateSlot();
    }

    public function testSpawnArgumentsRideTheSameSlotsInTheOppositeDirection(): void
    {
        $slots    = $this->slots();
        $argument = $slots->allocateSlot();
        $answer   = $slots->allocateSlot();

        // The parent hands work DOWN through a slot the child reads before starting
        $slots->complete($argument, 21);

        $child = $this->fork(static function () use ($slots, $argument, $answer): int {
            $input = $slots->await($argument, 5.0);
            if (!$input->isDone() || !\is_int($input->value)) {
                return self::WRONG_VALUE;
            }
            $slots->complete($answer, $input->value * 2);

            return self::OK;
        });

        $this->assertSame(42, $slots->await($answer, 10.0)->value);
        $this->assertSame(self::OK, $this->await($child));
        $this->assertInstanceOf(PersistentStore::class, $this->store());
    }
}
