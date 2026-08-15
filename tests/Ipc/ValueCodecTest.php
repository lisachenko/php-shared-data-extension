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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The tag contract: what a value record may carry, and what it must refuse
 *
 * The refusals matter as much as the round trips. Every value that cannot travel by address
 * has exactly one honest answer - a typed exception naming the remedy - because the only
 * alternative would be to encode it into bytes, which is the one thing this package does not
 * do at any price.
 */
class ValueCodecTest extends IpcTestCase
{
    /**
     * @return iterable<string, array{0: mixed, 1: ValueTag}>
     */
    public static function shareableValues(): iterable
    {
        yield 'null'          => [null, ValueTag::Nil];
        yield 'true'          => [true, ValueTag::True];
        yield 'false'         => [false, ValueTag::False];
        yield 'int'           => [42, ValueTag::Int];
        yield 'negative int'  => [-1_000_000, ValueTag::Int];
        yield 'max int'       => [PHP_INT_MAX, ValueTag::Int];
        yield 'float'         => [3.141592653589793, ValueTag::Float];
        yield 'negative zero' => [-0.0, ValueTag::Float];
        yield 'string'        => ['a string that lives in the arena', ValueTag::Str];
        yield 'empty string'  => ['', ValueTag::Str];
        yield 'binary string' => ["\x00\x01\xfe\xff", ValueTag::Str];
    }

    #[DataProvider('shareableValues')]
    public function testValuesSurviveTheirRecordUnchanged(mixed $value, ValueTag $expected): void
    {
        [$tag, $payload] = $this->codec()->encode($value);

        $this->assertSame($expected, $tag);
        $this->assertSame($value, $this->codec()->decode($tag, $payload));
    }

    public function testAStringRecordCarriesAnArenaAddressRatherThanBytes(): void
    {
        [$tag, $payload] = $this->codec()->encode('interned into the arena');

        $this->assertSame(ValueTag::Str, $tag);
        $this->assertTrue($tag->isAddress());
        $this->assertTrue($this->arena()->contains($payload, 8), 'the string was not interned into the arena');
    }

    public function testASharedObjectRecordCarriesItsAddress(): void
    {
        $shared = $this->sharedNode();

        [$tag, $payload] = $this->codec()->encode($shared);

        $this->assertSame(ValueTag::Obj, $tag);
        $this->assertSame($this->store()->addressOfInstance($shared), $payload);
        $this->assertSame($shared, $this->codec()->decode($tag, $payload));
    }

    public function testASharedArrayRecordCarriesItsAddress(): void
    {
        $array    = SharedArray::create($this->allocator(), $this->codec(), 4);
        $array[0] = 'inside a shared array';

        [$tag, $payload] = $this->codec()->encode($array);
        $this->assertSame(ValueTag::Arr, $tag);
        $this->assertSame($array->address(), $payload);

        $decoded = $this->codec()->decode($tag, $payload);
        $this->assertInstanceOf(SharedArray::class, $decoded);
        $this->assertSame($array->address(), $decoded->address());
        $this->assertSame('inside a shared array', $decoded[0]);
    }

    public function testAPlainArrayIsRefusedAndPointsAtSharedArray(): void
    {
        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/SharedArray/');

        $this->codec()->encode([1, 2, 3]);
    }

    public function testAClosureIsRefusedOnProvenanceAndPointsAtTheTaskTicket(): void
    {
        $this->expectException(NotShareableValueException::class);
        // Rejected because provenance cannot be recovered from the object, not because of
        // anything the closure looks like (EPIC #15, correction #8)
        $this->expectExceptionMessageMatches('/compiled BEFORE the fork barrier.+Task object/s');

        $this->codec()->encode(static fn (): int => 1);
    }

    public function testAResourceIsRefused(): void
    {
        $handle = fopen('php://memory', 'rb');
        $this->assertNotFalse($handle);

        try {
            $this->expectException(NotShareableValueException::class);
            $this->expectExceptionMessageMatches('/per-process table/');

            $this->codec()->encode($handle);
        } finally {
            fclose($handle);
        }
    }

    public function testAnOrdinaryObjectIsRefusedAndNamesPersist(): void
    {
        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/PersistentStore::persist\(/');

        $this->codec()->encode(new GraphNode());
    }

    public function testAnAddressOutsideTheArenaIsNeverDereferenced(): void
    {
        $this->expectException(NotShareableValueException::class);
        $this->expectExceptionMessageMatches('/not inside this arena/');

        // A record whose payload points anywhere else is refused before anything reads it
        $this->codec()->decode(ValueTag::Str, 0x1000);
    }

    public function testEveryRecordIsSixteenBytesOfTagAndPayload(): void
    {
        $this->assertSame(16, ValueRecord::SIZE);
        $this->assertSame(2, ValueRecord::WORDS);

        $address = $this->arena()->allocate(ValueRecord::SIZE);
        ValueRecord::write($this->arena(), $address, ValueTag::Int, -7);

        $this->assertSame(ValueTag::Int, ValueRecord::readTag($this->arena(), $address));
        $this->assertSame(-7, ValueRecord::readPayload($this->arena(), $address));

        // The seven padding bytes stay zero, which is what keeps the tag word readable as
        // the bare tag in every process
        $this->assertSame(ValueTag::Int->value, $this->arena()->readWord($address));
    }
}
