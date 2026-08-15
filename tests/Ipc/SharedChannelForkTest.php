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

/**
 * Channels between real processes: FIFO, blocking, rendezvous and close
 *
 * Every case forks. What separates these from a single-process exercise is that a value the
 * child put into the ring cannot reach the parent through inherited pages - the child was
 * running long before it sent - so the parent reading it proves the record really does live
 * in the shared mapping.
 */
class SharedChannelForkTest extends IpcTestCase
{
    public function testProducerAndConsumerChildrenExchangeRecordsInFifoOrder(): void
    {
        $channel = $this->channel(8);
        $count   = 200;

        $producer = $this->fork(static function () use ($channel, $count): int {
            for ($index = 0; $index < $count; $index++) {
                if (!$channel->send($index, 10.0)) {
                    return self::TIMED_OUT;
                }
            }
            $channel->close();

            return self::OK;
        });

        $consumer = $this->fork(static function () use ($channel, $count): int {
            for ($index = 0; $index < $count; $index++) {
                [$value, $ok] = $channel->recv(10.0);
                if (!$ok) {
                    return self::TIMED_OUT;
                }
                if ($value !== $index) {
                    // A ring is FIFO or it is nothing: an out-of-order record means two
                    // processes disagreed about head/tail
                    return self::WRONG_ORDER;
                }
            }

            // The producer closed after its last record: the stream ends, it does not stall
            [$value, $ok] = $channel->recv(10.0);

            return $value === null && $ok === false ? self::OK : self::WRONG_STATE;
        });

        $this->awaitAll([$producer, $consumer], 'the producer/consumer pair disagreed');
    }

    public function testBlockingReceiveWakesWhenAChildSendsMuchLater(): void
    {
        $channel = $this->channel(4);

        $sender = $this->fork(static function () use ($channel): int {
            // The parent is already parked on its notification socket by now; the value
            // arrives 300 ms into its wait, and the wake event is what ends that wait
            usleep(300_000);
            $channel->send('late arrival', 5.0);
            $channel->send(7, 5.0);

            return self::OK;
        });

        $before = microtime(true);
        [$value, $ok] = $channel->recv(10.0);
        $elapsed      = microtime(true) - $before;

        $this->assertTrue($ok, 'the blocking receive gave up before the child sent');
        $this->assertSame('late arrival', $value);
        $this->assertGreaterThan(0.2, $elapsed, 'the receive returned before the child could have sent');
        $this->assertLessThan(5.0, $elapsed, 'the receive did not wake on the event record');

        // The second record is already buffered; taking it must not block at all
        [$second, $ok] = $channel->recv(5.0);
        $this->assertTrue($ok);
        $this->assertSame(7, $second);

        $this->assertSame(self::OK, $this->await($sender));
    }

    public function testCapacityZeroChannelMakesTheSenderWaitForItsReceiver(): void
    {
        $channel = $this->channel(0);
        $this->assertTrue($channel->isRendezvous());

        $sender = $this->fork(static function () use ($channel): int {
            $before = microtime(true);
            if (!$channel->send('handoff', 10.0)) {
                return self::TIMED_OUT;
            }

            // The send is only allowed to return once the value has been TAKEN, and the
            // parent deliberately takes it 400 ms late
            return microtime(true) - $before > 0.2 ? self::OK : self::WRONG_STATE;
        });

        usleep(400_000);
        $this->assertLessThanOrEqual(1, $channel->count(), 'a rendezvous ring never buffers more than one handoff');

        [$value, $ok] = $channel->recv(10.0);
        $this->assertTrue($ok);
        $this->assertSame('handoff', $value);

        $this->assertSame(
            self::OK,
            $this->await($sender),
            'the rendezvous send returned before its receiver took the value',
        );
    }

    public function testRendezvousTrySendOnlySucceedsWhileAReceiverIsParked(): void
    {
        $channel = $this->channel(0);

        // Nobody is waiting: a non-blocking handoff has nowhere to go
        $this->assertFalse($channel->trySend('nobody home'));

        $receiver = $this->fork(static function () use ($channel): int {
            [$value, $ok] = $channel->recv(10.0);

            return $ok && $value === 'now somebody is' ? self::OK : self::WRONG_VALUE;
        });

        $deadline = microtime(true) + 5.0;
        $sent     = false;
        while (!$sent && microtime(true) < $deadline) {
            $sent = $channel->trySend('now somebody is');
            if (!$sent) {
                usleep(2_000);
            }
        }

        $this->assertTrue($sent, 'trySend never saw the parked receiver');
        $this->assertSame(self::OK, $this->await($receiver));
    }

    public function testCloseCrossesProcessesAndReceiversDrainWhatIsLeft(): void
    {
        $channel = $this->channel(8);
        $channel->send(1);
        $channel->send(2);
        $channel->send(3);

        $closer = $this->fork(static function () use ($channel): int {
            $channel->close();

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($closer));

        // Closed by ANOTHER process, and this one sees it through the shared flag
        $this->assertTrue($channel->isClosed());

        // Buffered records survive the close and are drained in order first
        foreach ([1, 2, 3] as $expected) {
            [$value, $ok] = $channel->recv(5.0);
            $this->assertTrue($ok);
            $this->assertSame($expected, $value);
        }

        [$value, $ok] = $channel->recv(5.0);
        $this->assertNull($value);
        $this->assertFalse($ok, 'a drained closed channel must report the end of stream');
    }

    public function testSendingIntoAChannelClosedByAnotherProcessThrows(): void
    {
        $channel = $this->channel(4);

        $closer = $this->fork(static function () use ($channel): int {
            $channel->close();

            return self::OK;
        });
        $this->assertSame(self::OK, $this->await($closer));

        $this->expectException(ClosedChannelException::class);
        $this->expectExceptionMessageMatches('/is closed; nothing can be sent/');

        $channel->trySend('too late');
    }

    public function testTwoProducersAndOneConsumerNeverLoseOrDuplicateARecord(): void
    {
        $channel = $this->channel(4);
        $perChild = 100;

        $producers = [];
        foreach ([1000, 2000] as $base) {
            $producers[] = $this->fork(static function () use ($channel, $perChild, $base): int {
                for ($index = 0; $index < $perChild; $index++) {
                    if (!$channel->send($base + $index, 10.0)) {
                        return self::TIMED_OUT;
                    }
                }

                return self::OK;
            });
        }

        $seen = [];
        for ($index = 0; $index < 2 * $perChild; $index++) {
            [$value, $ok] = $channel->recv(10.0);
            $this->assertTrue($ok, 'the consumer starved while producers were running');
            $seen[] = $value;
        }

        $this->awaitAll($producers, 'a producer failed');

        $this->assertCount(2 * $perChild, $seen);
        $this->assertCount(2 * $perChild, array_unique($seen), 'a record was delivered twice');
        $this->assertSame(0, $channel->count(), 'the ring is not empty after every record was taken');
    }

    public function testAChildSendsASharedObjectAndTheParentSeesTheSameAddress(): void
    {
        $channel = $this->channel(2);
        $store   = $this->store();

        $shared  = $this->sharedNode();
        $address = $store->addressOfInstance($shared);
        $this->assertNotNull($address);

        $sender = $this->fork(static function () use ($channel, $store, $address): int {
            $object = $store->attachObject($address);
            // The record carries eight bytes of address; the object never moves
            $channel->send($object, 5.0);

            return self::OK;
        });

        [$value, $ok] = $channel->recv(10.0);
        $this->assertTrue($ok);
        $this->assertInstanceOf(GraphNode::class, $value);
        $this->assertSame('shared-by-address', $value->name);
        $this->assertSame($address, $store->addressOfInstance($value), 'the object was copied instead of shared');

        $this->assertSame(self::OK, $this->await($sender));
    }

    public function testTheArenaWatermarkPlateausWhileRecordsChurnThroughARing(): void
    {
        $channel = $this->channel(16);

        // One lap to allocate everything the exchange needs
        $channel->send(1);
        $channel->recv(1.0);

        $before = $this->arena()->watermark();
        for ($index = 0; $index < 5_000; $index++) {
            $channel->send($index);
            [$value, $ok] = $channel->recv(1.0);
            $this->assertTrue($ok);
            $this->assertSame($index, $value);
        }

        // Scalars ride inside the record: a ring that never grows consumes nothing at all
        $this->assertSame($before, $this->arena()->watermark(), 'the ring leaked arena memory per record');
    }
}
