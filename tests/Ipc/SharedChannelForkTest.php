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

    public function testARegisteredReceiverIsARendezvousPartnerWithoutEverBeingInsideRecv(): void
    {
        $channel = $this->channel(0);
        $wake    = $this->wake();
        $ready   = AtomicInt::create($this->arena());

        $receiver = $this->fork(static function () use ($channel, $wake, $ready): int {
            // The shape a runtime with its own scheduler uses: announce the interest, then
            // wait in the event loop it already has. recv() is never called
            $token = $channel->registerReceiver();
            if ($token === null) {
                return self::WRONG_STATE;
            }
            $ready->set(1);

            $waits = 0;
            $end   = microtime(true) + 5.0;
            while ($channel->count() === 0 && microtime(true) < $end) {
                $waits++;
                $wake->wait(5.0);
            }
            $channel->cancelReceiver($token);

            $received = $channel->tryRecv();
            if ($received === null || $received[0] !== 'across the gate') {
                return self::WRONG_VALUE;
            }

            // Bounded, not exact: the deposit may already have landed by the time this loop is
            // reached, so zero waits is a legitimate outcome and so is one. What must never happen
            // is a stream of them - a consumer that had to poll for the handoff would come back
            // here over and over, and this bound is what makes that a failure instead of a
            // slowdown
            return $waits <= 2 ? self::OK : self::TIMED_OUT;
        });

        $this->assertTrue($this->awaitWord($ready->address(), 1), 'the receiver never registered');
        $this->assertSame(1, $channel->parkedReceivers());

        // The gate is open although nobody is inside recv() anywhere in this family
        $ticket = $channel->trySendTicket('across the gate');
        $this->assertNotNull($ticket, 'a registered receiver was not accepted as a rendezvous partner');

        $this->assertSame(self::OK, $this->await($receiver), 'the registered receiver disagreed');
        $this->assertTrue($channel->isTicketTaken($ticket), 'the handoff was never taken');
    }

    public function testRegisteringAReceiverWakesASenderParkedOnTheNotificationSocket(): void
    {
        $channel = $this->channel(0);
        $wake    = $this->wake();
        $parked  = AtomicInt::create($this->arena());

        $sender = $this->fork(static function () use ($channel, $wake, $parked): int {
            // Nobody is waiting, so there is nowhere to put the value yet
            if ($channel->trySendTicket('late partner') !== null) {
                return self::WRONG_STATE;
            }

            $token = $channel->registerSender();
            if ($token === null) {
                return self::WRONG_STATE;
            }
            $parked->set(1);

            // A registration is the only state change that can help this sender: no record is
            // published, no room is freed. If registerReceiver() did not wake parked senders,
            // this single bounded wait would come back empty and the test would fail rather
            // than be rescued by a re-poll
            $woken = $wake->wait(5.0) !== [];
            $channel->cancelSender($token);
            if (!$woken) {
                return self::TIMED_OUT;
            }

            $ticket = $channel->trySendTicket('late partner');
            if ($ticket === null) {
                return self::WRONG_STATE;
            }

            $end = microtime(true) + 5.0;
            while (!$channel->isTicketTaken($ticket) && microtime(true) < $end) {
                $wake->wait(1.0);
            }

            return $channel->isTicketTaken($ticket) ? self::OK : self::TIMED_OUT;
        });

        $this->assertTrue($this->awaitWord($parked->address(), 1), 'the sender never parked');

        $token = $channel->registerReceiver();
        $this->assertNotNull($token);

        $received = null;
        $end      = microtime(true) + 5.0;
        while ($received === null && microtime(true) < $end) {
            $wake->wait(1.0);
            $received = $channel->tryRecv();
        }
        $channel->cancelReceiver($token);

        $this->assertNotNull($received, 'the sender never deposited after being woken');
        $this->assertSame('late partner', $received[0]);
        $this->assertSame(self::OK, $this->await($sender), 'the parked sender disagreed');
    }

    public function testACancelledRegistrationStopsBeingARendezvousPartner(): void
    {
        $channel = $this->channel(0);

        $token = $channel->registerReceiver();
        $this->assertNotNull($token);
        $this->assertSame(1, $channel->parkedReceivers());
        $this->assertTrue($channel->trySend('while registered'));

        // Drain, so the refusal below is about the partner and not about the one ring slot
        $this->assertNotNull($channel->tryRecv());

        $channel->cancelReceiver($token);

        $this->assertSame(0, $channel->parkedReceivers());
        $this->assertFalse($channel->trySend('after cancelling'), 'a cancelled registration still gated a send');
    }

    public function testCancellingAnAlreadyFreeRegistrationDoesNotDriveTheParkedCountNegative(): void
    {
        $channel = $this->channel(0);

        $token = $channel->registerReceiver();
        $this->assertNotNull($token);

        $channel->cancelReceiver($token);
        $channel->cancelReceiver($token);

        $this->assertSame(0, $channel->parkedReceivers());

        // And the channel still works: the count was not corrupted by the repeat
        $again = $channel->registerReceiver();
        $this->assertNotNull($again);
        $this->assertSame(1, $channel->parkedReceivers());
        $this->assertTrue($channel->trySend('still a partner'));
    }

    public function testARecordDepositedAgainstACancelledRegistrationStaysForTheNextReceiver(): void
    {
        $channel = $this->channel(0);

        $token = $channel->registerReceiver();
        $this->assertNotNull($token);

        // The exact race a select loser runs into: the deposit lands, and the registration it
        // landed against is withdrawn before anybody took the value
        $ticket = $channel->trySendTicket('deposited then abandoned');
        $this->assertNotNull($ticket);
        $channel->cancelReceiver($token);

        // Nothing is lost and nothing is owed: the record is in the ring, the sender's ticket
        // is still untaken, and the next receiver completes the handshake
        $this->assertFalse($channel->isTicketTaken($ticket), 'the abandoned handoff counted as taken');
        $this->assertSame(1, $channel->count());

        [$value, $ok] = $channel->recv(5.0);
        $this->assertTrue($ok);
        $this->assertSame('deposited then abandoned', $value);
        $this->assertTrue($channel->isTicketTaken($ticket));
    }

    public function testADeadWorkersRegistrationDoesNotMakeALaterSendBelieveAPartnerIsPresent(): void
    {
        $channel = $this->channel(0);

        $registrant = $this->fork(static function () use ($channel): int {
            // Registers and dies holding the entry, which is what a killed worker does
            return $channel->registerReceiver() === null ? self::WRONG_STATE : self::OK;
        });
        $this->assertSame(self::OK, $this->await($registrant));

        // The entry outlived its owner, exactly as a stale registration would
        $this->assertSame(1, $channel->parkedReceivers());

        $this->assertFalse(
            $channel->trySend('nobody is really there'),
            'a dead worker\'s registration was accepted as a rendezvous partner',
        );
        $this->assertSame(0, $channel->parkedReceivers(), 'the dead registration was not reaped');
        $this->assertSame(0, $channel->count(), 'a value was deposited for a receiver that no longer exists');
    }

    public function testTheReceiversTableRefusesARegistrationPastItsCapacityAndNamesIt(): void
    {
        $channel = $this->channel(0, waiters: 1);

        $this->assertNotNull($channel->registerReceiver());

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/receivers waiter table of this channel is full/');

        $channel->registerReceiver();
    }

    public function testRegistrationsCancelledUnderContentionNeverLoseOrDuplicateAHandoff(): void
    {
        $channel = $this->channel(0);
        $wake    = $this->wake();
        $rounds  = 100;
        $taken   = AtomicInt::create($this->arena());
        $sum     = AtomicInt::create($this->arena());

        $receiver = $this->fork(static function () use ($channel, $wake, $rounds, $taken, $sum): int {
            $got      = 0;
            $lap      = 0;
            $deadline = microtime(true) + 25.0;

            while ($got < $rounds) {
                if (microtime(true) > $deadline) {
                    return self::TIMED_OUT;
                }
                $lap++;

                $token = $channel->registerReceiver();
                if ($token === null) {
                    // A record was already there, so nothing was registered
                    $received = $channel->tryRecv();
                    if ($received !== null && $received[1]) {
                        $got++;
                        $taken->add(1);
                        $sum->add($received[0]);
                    }

                    continue;
                }

                if ($lap % 3 === 0) {
                    // The select-loser interleaving, driven on purpose: withdraw the moment
                    // after registering, so a deposit lands against a partner that is leaving
                    $channel->cancelReceiver($token);

                    continue;
                }

                $wake->wait(0.2);
                $channel->cancelReceiver($token);

                $received = $channel->tryRecv();
                if ($received !== null && $received[1]) {
                    $got++;
                    $taken->add(1);
                    $sum->add($received[0]);
                }
            }

            return self::OK;
        });

        $sender = $this->fork(static function () use ($channel, $wake, $rounds): int {
            $deadline = microtime(true) + 25.0;

            for ($value = 1; $value <= $rounds; $value++) {
                $ticket = null;
                while ($ticket === null) {
                    if (microtime(true) > $deadline) {
                        return self::TIMED_OUT;
                    }
                    $ticket = $channel->trySendTicket($value);
                    if ($ticket === null) {
                        $token = $channel->registerSender();
                        if ($token !== null) {
                            $wake->wait(0.2);
                            $channel->cancelSender($token);
                        }
                    }
                }

                // A rendezvous send is only over once the record has been TAKEN, so the next
                // value is only offered after this one completed its handshake
                while (!$channel->isTicketTaken($ticket)) {
                    if (microtime(true) > $deadline) {
                        return self::TIMED_OUT;
                    }
                    $token = $channel->registerSender($ticket);
                    if ($token !== null) {
                        $wake->wait(0.2);
                        $channel->cancelSender($token);
                    }
                }
            }

            return self::OK;
        });

        $this->awaitAll([$receiver, $sender], 'a rendezvous partner gave up under contention');

        $this->assertSame($rounds, $taken->get(), 'a handoff was lost while a registration was cancelled');
        $this->assertSame(
            $rounds * ($rounds + 1) / 2,
            $sum->get(),
            'a handoff was delivered twice, or a different value than the one sent',
        );
        $this->assertSame(0, $channel->count(), 'the rendezvous slot is not empty after every value was taken');
        $this->assertSame(0, $channel->parkedReceivers(), 'a registration outlived the exchange');
        $this->assertSame(0, $channel->parkedSenders(), 'a sender registration outlived the exchange');
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
