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

/**
 * Slots come back, and an old handle never gets somebody else's answer
 *
 * The arena is bump-only and children may never free, so a recycled slot is the SAME record
 * reused in place - there is no other shape recycling could take here. What makes that safe is
 * that a slot id is a {@see SlotTicket}: index plus generation. The generation moves the moment
 * an owner releases its claim, so a handle held one moment too long is refused by name instead
 * of being answered with whatever task got the slot next.
 *
 * Every case below is about one of the two halves: the supply really does come back, and the
 * refusal really is loud.
 */
class ResultSlotRecyclingForkTest extends IpcTestCase
{
    private function slots(int $capacity = 64): ResultSlotTable
    {
        return ResultSlotTable::create($this->allocator(), $this->codec(), $this->wake(), $capacity, null);
    }

    public function testAReleasedSlotIsHandedOutAgainUnderANewGeneration(): void
    {
        $slots = $this->slots(4);

        $first = $slots->allocateSlot();
        $slots->complete($first, 'the first answer');

        $this->assertSame('the first answer', $slots->readSlot($first)->value);
        $this->assertSame(1, $slots->outstanding());

        $slots->releaseSlot($first);
        $this->assertSame(0, $slots->outstanding());

        $second = $slots->allocateSlot();

        $this->assertSame(
            SlotTicket::indexOf($first),
            SlotTicket::indexOf($second),
            'the released slot record was not reused',
        );
        $this->assertSame(SlotTicket::generationOf($first) + 1, SlotTicket::generationOf($second));
        $this->assertNotSame($first, $second, 'the ticket must change even though the slot did not');

        // The record is genuinely reset, not merely re-labelled
        $this->assertTrue($slots->readSlot($second)->isPending());
        $this->assertSame(1, $slots->recycled());
        $this->assertSame(1, $slots->highWaterMark(), 'a recycled slot must not move the bump cursor');
    }

    public function testASteadyStreamOfResultsKeepsReusingOneSlot(): void
    {
        $slots = $this->slots(4);

        for ($round = 0; $round < 1_000; $round++) {
            $ticket = $slots->allocateSlot();
            $slots->complete($ticket, $round);

            $this->assertSame($round, $slots->readSlot($ticket)->value);

            $slots->releaseSlot($ticket);
        }

        // The whole point of the change: a thousand results, one slot record, nothing climbing
        $this->assertSame(1, $slots->highWaterMark());
        $this->assertSame(0, $slots->outstanding());
        $this->assertSame(4, $slots->available());
        $this->assertSame(999, $slots->recycled());
    }

    public function testAStaleHandleIsRefusedByEveryVerbRatherThanAnsweredWithTheNextResult(): void
    {
        $slots = $this->slots(4);

        $stale = $slots->allocateSlot();
        $slots->complete($stale, 'the answer the stale handle read');
        $slots->releaseSlot($stale);

        $fresh = $slots->allocateSlot();
        $slots->complete($fresh, 'the answer that belongs to somebody else');

        $verbs = [
            'readSlot'      => static fn(): mixed => $slots->readSlot($stale),
            'await'         => static fn(): mixed => $slots->await($stale, 0.05),
            'complete'      => static fn(): mixed => $slots->complete($stale, 'too late'),
            'completePanic' => static fn(): mixed => $slots->completePanic($stale, $slots->address()),
            'releaseSlot'   => static fn(): mixed => $slots->releaseSlot($stale),
        ];

        foreach ($verbs as $verb => $call) {
            try {
                $call();
                $this->fail(sprintf('%s() answered a stale handle instead of refusing it', $verb));
            } catch (IpcException $refused) {
                $this->assertStringContainsString('generation', $refused->getMessage(), $verb);
                $this->assertStringContainsString(
                    sprintf('slot %d', SlotTicket::indexOf($stale)),
                    $refused->getMessage(),
                    $verb,
                );
            }
        }

        // And the task that legitimately owns the slot is untouched by all that
        $this->assertSame('the answer that belongs to somebody else', $slots->readSlot($fresh)->value);
    }

    public function testABareSlotIndexIsNotAValidHandle(): void
    {
        $slots  = $this->slots(4);
        $ticket = $slots->allocateSlot();

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/generation 0/');

        // Generation 0 never exists, so the integer that looks most like the old slot id is
        // refused rather than quietly addressing the first generation of that slot
        $slots->readSlot(SlotTicket::indexOf($ticket));
    }

    public function testAPendingSlotIsRefusedRatherThanRecycledUnderItsWriter(): void
    {
        $slots  = $this->slots(4);
        $ticket = $slots->allocateSlot();

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/still pending and cannot be released/');

        $slots->releaseSlot($ticket);
    }

    public function testReleasingTwiceIsRefusedRatherThanDoubleFreeingTheSlot(): void
    {
        $slots  = $this->slots(4);
        $ticket = $slots->allocateSlot();
        $slots->complete($ticket, 1);
        $slots->releaseSlot($ticket);

        $this->expectException(IpcException::class);
        $this->expectExceptionMessageMatches('/generation/');

        $slots->releaseSlot($ticket);
    }

    public function testATableWhoseSlotsAreAllGenuinelyHeldStillFillsUp(): void
    {
        $slots = $this->slots(2);

        $first  = $slots->allocateSlot();
        $second = $slots->allocateSlot();

        try {
            $slots->allocateSlot();
            $this->fail('a full table handed out a third slot');
        } catch (IpcException $full) {
            $this->assertStringContainsString('All 2 result slots are in use', $full->getMessage());
            $this->assertStringContainsString('2 outstanding', $full->getMessage());
            $this->assertStringContainsString('releaseSlot()', $full->getMessage());
        }

        // Releasing one is what makes room again, and only that
        $slots->complete($first, 'read and done with');
        $slots->readSlot($first);
        $slots->releaseSlot($first);

        $third = $slots->allocateSlot();
        $this->assertSame(SlotTicket::indexOf($first), SlotTicket::indexOf($third));
        $this->assertNotSame(SlotTicket::indexOf($second), SlotTicket::indexOf($third));
    }

    public function testASlotIsRetiredRatherThanLettingItsGenerationWrapRound(): void
    {
        $slots = $this->slots(1);

        for ($use = 0; $use < SlotTicket::MAX_GENERATION; $use++) {
            $ticket = $slots->allocateSlot();
            $slots->complete($ticket, $use);
            $slots->releaseSlot($ticket);
        }

        $this->assertSame(1, $slots->retired(), 'the slot was recycled past the end of its counter');
        $this->assertSame(0, $slots->available());

        try {
            $slots->allocateSlot();
            $this->fail('a retired slot was handed out again');
        } catch (IpcException $full) {
            $this->assertStringContainsString('1 retired', $full->getMessage());
        }
    }

    public function testASlotRecycledByTheParentCarriesAChildsResultAndRefusesTheOldHandle(): void
    {
        $slots  = $this->slots(2);
        $arena  = $this->arena();
        $report = $arena->allocate(8);
        $arena->writeWord($report, 0);

        // Round one: settled here, read here, given back here
        $first = $slots->allocateSlot();
        $slots->complete($first, 'round one');
        $this->assertSame('round one', $slots->readSlot($first)->value);
        $slots->releaseSlot($first);

        // Round two lands on the very same record, and a CHILD settles it
        $second = $slots->allocateSlot();
        $this->assertSame(SlotTicket::indexOf($first), SlotTicket::indexOf($second));

        $child = $this->fork(static function () use ($slots, $first, $second, $arena, $report): int {
            // The stale ticket of round one must not settle round two's slot from over here
            try {
                $slots->complete($first, 'a result from the wrong generation');

                return self::WRONG_STATE;
            } catch (IpcException) {
                $arena->writeWord($report, 1);
            }

            usleep(150_000);
            $slots->complete($second, 'round two, settled by the child');

            return self::OK;
        });

        $result = $slots->await($second, 10.0);

        $this->assertTrue($result->isDone());
        $this->assertSame('round two, settled by the child', $result->value);
        $this->assertSame(self::OK, $this->await($child));
        $this->assertSame(1, $arena->readWord($report), 'the child was allowed to use the stale ticket');

        // The parent's own copy of the dead handle is refused just as flatly
        $this->expectException(IpcException::class);
        $slots->readSlot($first);
    }

    public function testASpawnArgumentSlotIsReleasedByItsReaderAndComesBackToTheTable(): void
    {
        $slots    = $this->slots(2);
        $argument = $slots->allocateSlot();
        $answer   = $slots->allocateSlot();

        $slots->complete($argument, 21);

        $child = $this->fork(static function () use ($slots, $argument, $answer): int {
            $input = $slots->await($argument, 5.0);
            if (!$input->isDone() || !\is_int($input->value)) {
                return self::WRONG_VALUE;
            }

            // The reader of a one-shot downward slot is the one that gives it back: the parent
            // has nothing left to read there and would only be guessing when it is safe
            $slots->releaseSlot($argument);
            $slots->complete($answer, $input->value * 2);

            return self::OK;
        });

        $this->assertSame(42, $slots->await($answer, 10.0)->value);
        $this->assertSame(self::OK, $this->await($child));

        // Released in the child, visible as free in the parent: the free list is arena state
        $slots->releaseSlot($answer);
        $this->assertSame(0, $slots->outstanding());
        $this->assertSame(2, $slots->available());

        $reused = $slots->allocateSlot();
        $this->assertContains(
            SlotTicket::indexOf($reused),
            [SlotTicket::indexOf($argument), SlotTicket::indexOf($answer)],
        );
        $this->assertSame(2, SlotTicket::generationOf($reused));
    }
}
