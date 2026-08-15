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

use Lisachenko\SharedData\Stub\GraphNode;

require_once __DIR__ . '/serialization-guard.php';

/**
 * The two claims the whole epic rests on, tested rather than asserted in prose
 *
 * 1. the sockets between workers carry fixed 16-byte event records and NOTHING else;
 * 2. no value crossing a worker boundary passes through serialize(), igbinary or JSON.
 *
 * Both are checked by observing the real code path: the registry's single write choke point
 * for the first, namespace-local shadows of every encoding function for the second.
 */
class NotificationPlaneForkTest extends IpcTestCase
{
    public function testAnEventRecordWrittenByAChildWakesTheParentIntact(): void
    {
        $wake       = $this->wake();
        $parentSlot = $wake->slot();
        $address    = $this->arena()->allocate(16);

        $child = $this->fork(static function () use ($wake, $parentSlot, $address): int {
            // The child claims a slot of its own, then pokes the parent's
            $wake->slot();
            usleep(150_000);
            $wake->notify($parentSlot, new WakeEvent(WakeOpcode::Result, 7, ValueTag::Obj, $address));

            return self::OK;
        });

        $events = [];
        $deadline = microtime(true) + 10.0;
        while ($events === [] && microtime(true) < $deadline) {
            $events = $wake->wait(1.0);
        }

        $this->assertCount(1, $events, 'the parent never received the child\'s event record');
        $this->assertSame(WakeOpcode::Result, $events[0]->opcode);
        $this->assertSame(7, $events[0]->id);
        $this->assertSame(ValueTag::Obj, $events[0]->tag);
        $this->assertSame($address, $events[0]->address, 'the address did not survive the record');

        $this->assertSame(self::OK, $this->await($child));
    }

    public function testEveryByteThatCrossesASocketIsAFixedEventRecord(): void
    {
        $wake    = $this->wake();
        $channel = $this->channel(4);
        $slots   = ResultSlotTable::create($this->allocator(), $this->codec(), $wake, 8, null);

        /** @var list<string> $written */
        $written = [];
        $wake->observeWrites(static function (int $slot, string $bytes) use (&$written): void {
            $written[] = $bytes;
        });

        try {
            $secret = 'a payload nobody may ever read off a socket';

            // A full round trip with a receiver parked, so wake events really are written
            $receiver = $this->fork(static function () use ($channel, $secret): int {
                [$value, $ok] = $channel->recv(10.0);

                return $ok && $value === $secret ? self::OK : self::WRONG_VALUE;
            });

            usleep(150_000);
            $channel->send($secret, 10.0);
            $this->assertSame(self::OK, $this->await($receiver));

            $id = $slots->allocateSlot();
            $slots->complete($id, $secret);
            $slots->complete($slots->allocateSlot(), 12345);
        } finally {
            $wake->observeWrites(null);
        }

        $this->assertNotSame([], $written, 'no notification was written at all - the test proves nothing');

        foreach ($written as $bytes) {
            $this->assertSame(WakeEvent::SIZE, \strlen($bytes), 'a socket write was not a fixed event record');

            $event = WakeEvent::fromBytes($bytes);
            $this->assertNotNull($event, 'a socket write did not parse as an event record');

            // An event may name an ADDRESS, never a value: scalar tags carry a zero there
            if ($event->tag->isAddress()) {
                $this->assertTrue(
                    $this->arena()->contains($event->address, 8),
                    'an event carried an address outside the arena',
                );
            } else {
                $this->assertSame(0, $event->address, 'an event carried payload bytes for a scalar value');
            }
        }

        $this->assertStringNotContainsString(
            'a payload nobody may ever read off a socket',
            implode('', $written),
            'value bytes leaked onto the notification socket',
        );
    }

    public function testAFullProducerConsumerRoundTripCallsNoEncodingFunctionAtAll(): void
    {
        // First prove the guard can actually see a call: an unqualified serialize() from
        // this namespace resolves to the shadow, exactly as it would from package code
        SerializationGuard::reset();
        serialize('proof that the guard is wired up');
        $this->assertSame([__NAMESPACE__ . '\serialize'], SerializationGuard::calls());

        $channel = $this->channel(8);
        $array   = SharedArray::create($this->allocator(), $this->codec(), 4);
        $slots   = ResultSlotTable::create($this->allocator(), $this->codec(), $this->wake(), 8, null);

        $shared = $this->sharedNode();

        SerializationGuard::reset();

        $child = $this->fork(static function () use ($channel, $slots): int {
            $slotId = 0;
            for ($index = 0; $index < 4; $index++) {
                [$value, $ok] = $channel->recv(10.0);
                if (!$ok) {
                    return self::TIMED_OUT;
                }
                if ($index === 3) {
                    $slots->complete($slotId, $value instanceof GraphNode ? $value->name : 'wrong');
                }
            }

            return self::OK;
        });

        $slotId = $slots->allocateSlot();
        $this->assertSame(0, $slotId);

        $array[0] = 'inside the shared array';
        $channel->send('a string of real bytes', 10.0);
        $channel->send(1234, 10.0);
        $channel->send($array, 10.0);
        $channel->send($shared, 10.0);

        $result = $slots->await($slotId, 10.0);
        $this->assertSame('shared-by-address', $result->value);
        $this->assertSame(self::OK, $this->await($child));

        $this->assertSame(
            [],
            SerializationGuard::calls(),
            'a value was encoded on the way between processes: ' . implode(', ', SerializationGuard::calls()),
        );
    }

    public function testTheRegistryRecyclesTheSlotOfAWorkerThatDied(): void
    {
        $wake = $this->wake();
        $wake->slot();

        $taken = [];
        for ($round = 0; $round < 3; $round++) {
            $arena  = $this->arena();
            $report = $arena->allocate(8);
            $arena->writeWord($report, -1);

            $child = $this->fork(static function () use ($wake, $arena, $report): int {
                $arena->writeWord($report, $wake->slot());

                return self::OK;
            });
            $this->assertSame(self::OK, $this->await($child));

            $taken[] = $arena->readWord($report);
        }

        // Every child took a slot, and dead workers' slots come back: a supervisor may
        // respawn forever without exhausting a table sized for the pool
        $this->assertCount(3, $taken);
        foreach ($taken as $slot) {
            $this->assertGreaterThanOrEqual(0, $slot);
            $this->assertLessThan($wake->capacity(), $slot);
        }
        $this->assertLessThanOrEqual(2, \count(array_unique($taken)), 'dead workers never gave their slots back');
    }
}
