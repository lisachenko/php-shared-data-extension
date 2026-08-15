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
 * Closure exchange, Phase A: a closure compiled before the fork, invoked in several workers
 *
 * The claim under test is narrow and it is the only one Phase A makes: a closure REGISTERED
 * before the fork barrier can be reached by address in every process of the family and
 * invoked there, concurrently, with correct results - and nothing else can. Registration is
 * the whole acceptance test, so the refusals matter as much as the invocations: a closure the
 * register does not know is refused by the codec exactly as it was before this feature
 * existed, and a registration attempted after the barrier (or from a worker) is refused
 * outright rather than resolved into something plausible.
 *
 * Everything the children answer with travels as an exit code or as an arena word. The record
 * addresses themselves reach them either over a socket pair, as eight machine-order bytes, or
 * inside a value record on a SharedChannel - never as a serialized value.
 */
final class SharedClosureForkTest extends IpcTestCase
{
    private const int ROUNDS = 100_000;

    private const int SCALE_FACTOR = 3;

    /**
     * Register of this suite, created and filled ONCE per process, before anything forks
     */
    private static ?ClosureProvenance $closures = null;

    /**
     * A second register kept deliberately open, so the "workers never register" rule can be
     * observed on its own instead of behind the barrier refusal
     */
    private static ?ClosureProvenance $openRegister = null;

    private static ?ValueCodec $closureCodec = null;

    /**
     * @var array<string, int> name => record address
     */
    private static array $records = [];

    /**
     * The register every test shares: closures registered, barrier marked, nothing pending
     *
     * Test order is deliberately irrelevant here. Registration is a pre-fork, one-shot phase
     * of a process's life, not something a test may re-open, so the whole phase runs once in
     * this accessor and every case observes the same finished state.
     */
    private function closures(): ClosureProvenance
    {
        if (self::$closures !== null) {
            return self::$closures;
        }
        $register = ClosureProvenance::create($this->allocator(), $this->store(), 16, 'closures-suite');

        $factor = self::SCALE_FACTOR;
        $node   = $this->sharedNode();

        self::$records['scale']  = $register->registerSharedClosure(
            'scale',
            static fn (int $value): int => $value * $factor,
        );
        self::$records['square'] = $register->registerSharedClosure(
            'square',
            static fn (int $value): int => $value * $value,
        );
        self::$records['name']   = $register->registerSharedClosure(
            'name',
            static fn (): string => $node->name,
        );

        $register->markForkBarrier();

        return self::$closures = $register;
    }

    private function openRegister(): ClosureProvenance
    {
        return self::$openRegister ??= ClosureProvenance::create(
            $this->allocator(),
            $this->store(),
            4,
            'closures-open',
        );
    }

    /**
     * A codec that knows the register - the only difference from the suite's default one
     */
    private function closureCodec(): ValueCodec
    {
        return self::$closureCodec ??= new ValueCodec($this->allocator(), $this->store(), $this->closures());
    }

    public function testTwoChildrenInvokeAPreForkClosureAHundredThousandTimesEach(): void
    {
        $register = $this->closures();
        $record   = self::$records['scale'];
        $sums     = $this->arena()->allocate(16);

        $expected = 0;
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $expected += $round * self::SCALE_FACTOR;
        }

        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pair = [];
            $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair), 'no socket pair');

            $slot       = $sums + $worker * 8;
            $children[] = $this->fork(function () use ($pair, $register, $slot): int {
                socket_close($pair[0]);
                $bytes = socket_read($pair[1], 8, PHP_BINARY_READ);
                socket_close($pair[1]);
                if ($bytes === false || \strlen($bytes) !== 8) {
                    return self::WRONG_STATE;
                }
                /** @var array{1: int} $address */
                $address = unpack('Q', $bytes);

                // Eight bytes arrived; the closure they name is invoked entirely in this process
                $scale = $register->resolve($address[1]);

                $sum = 0;
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $sum += $scale($round);
                }
                $this->arena()->writeWord($slot, $sum);

                return self::OK;
            });

            socket_close($pair[1]);
            socket_write($pair[0], pack('Q', $record), 8);
            socket_close($pair[0]);
        }

        $this->awaitAll($children, 'a child could not invoke the shared closure');

        $this->assertSame($expected, $this->arena()->readWord($sums), 'first worker computed a different sum');
        $this->assertSame($expected, $this->arena()->readWord($sums + 8), 'second worker computed a different sum');
    }

    public function testAClosureCrossesAChannelAsARecordAddressAndRunsInTheReceiver(): void
    {
        $codec   = $this->closureCodec();
        $channel = SharedChannel::create($this->allocator(), $codec, $this->wake(), 4);
        $answer  = $this->arena()->allocate(8);

        $child = $this->fork(function () use ($channel, $answer): int {
            [$closure, $received] = $channel->recv(5.0);
            if (!$received || !$closure instanceof \Closure) {
                return self::WRONG_STATE;
            }
            $this->arena()->writeWord($answer, $closure(12));

            return self::OK;
        });

        $channel->send($this->closures()->closure('square'), 5.0);

        $this->assertSame(self::OK, $this->await($child), 'the child could not run the closure it received');
        $this->assertSame(144, $this->arena()->readWord($answer));
    }

    public function testAClosureCapturingASharedObjectReadsItInEveryWorker(): void
    {
        $register = $this->closures();
        $record   = self::$records['name'];
        $expected = $this->sharedNode()->name;

        $child = $this->fork(static function () use ($register, $record, $expected): int {
            $describe = $register->resolve($record);

            return $describe() === $expected ? self::OK : self::WRONG_VALUE;
        });

        $this->assertSame(self::OK, $this->await($child), 'the captured shared object did not read back');
    }

    public function testTheRecordTravelsWhileTheClosureItselfStaysWhereItWasCompiled(): void
    {
        $closure = $this->closures()->closure('square');

        [$tag, $payload] = $this->closureCodec()->encode($closure);

        $this->assertSame(ValueTag::Closure, $tag);
        $this->assertTrue($tag->isAddress());
        $this->assertSame(self::$records['square'], $payload);
        $this->assertTrue($this->arena()->contains($payload, 8), 'the record does not live in the arena');

        // Phase A copies nothing: the object stays in the memory the family inherited, and only
        // the RECORD that vouches for it is shared (docs/closure-cloning.md)
        $this->assertFalse(
            $this->arena()->contains($this->closures()->closureAddressOf('square'), 8),
            'the closure object itself was copied into the arena',
        );

        $decoded = $this->closureCodec()->decode($tag, $payload);
        $this->assertInstanceOf(\Closure::class, $decoded);
        $this->assertSame(49, $decoded(7));
    }

    public function testRegisteringAfterTheForkBarrierIsRefused(): void
    {
        $register = $this->closures();
        $this->assertTrue($register->isForkBarrierPassed());

        $this->expectException(ClosureProvenanceException::class);
        $this->expectExceptionMessageMatches('/already marked the fork barrier/');

        $register->registerSharedClosure('too-late', static fn (): int => 1);
    }

    public function testAWorkerNeverRegistersAClosureOfItsOwnEvenBeforeAnyBarrier(): void
    {
        $register = $this->openRegister();
        $this->assertFalse($register->isForkBarrierPassed(), 'this register is deliberately still open');

        $child = $this->fork(static function () use ($register): int {
            try {
                $register->registerSharedClosure('from-a-worker', static fn (): int => 1);
            } catch (ClosureProvenanceException) {
                return self::OK;
            }

            return self::WRONG_STATE;
        });

        $this->assertSame(self::OK, $this->await($child), 'a worker was allowed to register a closure');
        $this->assertNotContains('from-a-worker', $register->names(), 'a worker wrote a record into the table');
    }

    public function testAnUnregisteredClosureIsRefusedAndNamesTheRegistration(): void
    {
        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/registerSharedClosure/');

        $this->closureCodec()->encode(static fn (): int => 1);
    }

    public function testACodecWithoutARegisterRefusesEveryClosureExactlyAsBefore(): void
    {
        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/compiled BEFORE the fork barrier.+Task object/s');

        $this->codec()->encode($this->closures()->closure('square'));
    }

    public function testAnAddressThatIsNotARecordIsRefusedBeforeAnythingIsDereferenced(): void
    {
        $this->expectException(ClosureProvenanceException::class);
        $this->expectExceptionMessageMatches('/is not a closure record/');

        // Inside the table, but four bytes past the start of a record: a plausible address is
        // still refused, because the check is arithmetic on our own layout and not a guess
        $this->closures()->resolve(self::$records['scale'] + 8);
    }

    public function testACaptureByReferenceIsRefused(): void
    {
        $counter = 0;

        $this->expectException(ClosureProvenanceException::class);
        $this->expectExceptionMessageMatches('/captures \$counter by reference/');

        $this->openRegister()->registerSharedClosure('by-reference', static function () use (&$counter): void {
            $counter++;
        });
    }

    public function testAStaticVariableInsideAClosureIsRefused(): void
    {
        $this->expectException(ClosureProvenanceException::class);
        $this->expectExceptionMessageMatches('/declares the static variable \$seen/');

        $this->openRegister()->registerSharedClosure('with-static', static function (): int {
            static $seen = 0;

            return ++$seen;
        });
    }

    public function testACapturedPlainArrayIsRefusedAndPointsAtSharedArray(): void
    {
        $rows = [1, 2, 3];

        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/SharedArray/');

        $this->openRegister()->registerSharedClosure('with-array', static fn (): int => \count($rows));
    }

    public function testACapturedRequestObjectIsRefusedAndNamesPersist(): void
    {
        $node = new GraphNode();

        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/PersistentStore::persist\(/');

        $this->openRegister()->registerSharedClosure('with-object', static fn (): string => $node->name);
    }

    public function testAClosureBoundToARequestObjectIsRefused(): void
    {
        $node  = new GraphNode();
        $bound = \Closure::bind(function (): string {
            return $this->name;
        }, $node, GraphNode::class);

        $this->expectException(ClosureProvenanceException::class);
        $this->expectExceptionMessageMatches('/bound to an instance of/');

        $this->openRegister()->registerSharedClosure('bound-to-request', $bound);
    }

    public function testAClosureBoundToASharedObjectIsAcceptedAndRunsInAWorker(): void
    {
        $shared = $this->sharedNode();
        $bound  = \Closure::bind(function (): int {
            return $this->counter;
        }, $shared, GraphNode::class);

        $register = $this->openRegister();
        $record   = $register->registerSharedClosure('bound-to-shared', $bound);

        $this->assertSame(
            $this->store()->sharedIdOf($shared),
            $register->boundThisAddressOf('bound-to-shared'),
            'the record does not carry the arena address of the bound $this',
        );

        $expected = $shared->counter;
        $child    = $this->fork(static function () use ($register, $record, $expected): int {
            $read = $register->resolve($record);

            return $read() === $expected ? self::OK : self::WRONG_VALUE;
        });

        $this->assertSame(self::OK, $this->await($child), 'the bound shared object did not read back in a worker');
    }
}
