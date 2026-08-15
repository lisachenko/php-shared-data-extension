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
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;
use ZEngine\Core;
use ZEngine\Generated\zend_closure;
use ZEngine\Generated\zend_object;
use ZEngine\Reflection\ReflectionFunction;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\Type\ClosureEntry;
use ZEngine\Type\PersistentObjectFactory;

/**
 * The register of closures a worker family may invoke by address - Phase A of closure exchange
 *
 * ## Why registration, and nothing but registration, is the acceptance test
 *
 * A closure compiled BEFORE the fork is safe in every worker: the `zend_closure`, its embedded
 * `zend_function` and every pointer inside them were laid out by the parent, so the whole
 * family maps them at the same addresses, copy-on-write. A closure compiled AFTER the fork is
 * the opposite, and it does not fail loudly: spike S17 held a stale address in a sibling and
 * found a different, perfectly valid `Closure` there, which then executed the WRONG FUNCTION
 * (EPIC #15, correction #8; docs/shared-memory-model.md, §7). On 8.4 the same experiment
 * segfaulted. Nothing about the object distinguishes the two cases - not its class entry, not
 * its handlers, not its op_array - so this class never looks at one to decide.
 *
 * What it does instead is trivially sound: the process that owns the arena registers closures
 * by name BEFORE it forks, each registration writing a record into the arena; the fork barrier
 * is then marked, and every later registration is refused. A record therefore exists only for
 * a closure that was already compiled when the family was created, which is precisely the
 * property that makes the address portable. Everything else stays refused by ValueCodec.
 *
 * ```text
 *   header (8 words, 64 bytes)   magic | capacity | count | creator pid | barrier pid
 *   records (64 bytes each)      closure address | zend_function address | name length |
 *                                bound $this address | name[32]
 * ```
 *
 * The records live in the arena; the closures themselves do NOT. There is no copy: a shared
 * closure is the parent's own object, inherited by every child at the same address, and the
 * record is the arena-resident proof that it was there before the fork. That is also why the
 * table is a consumer structure with its own roots-directory name rather than part of the
 * registry layout - it adds no field to any persisted record, so `Registry::LAYOUT_VERSION`
 * is unchanged by it.
 *
 * ## What a shared closure may capture
 *
 * A worker invokes the closure against ITS OWN copy-on-write copy of everything the closure
 * points at, so anything mutable that is not in the arena diverges per process, silently:
 *
 *  - **bound `$this`** must be null or an object of this store (an arena address every worker
 *    resolves to the same object);
 *  - **`use`d values** follow the ordinary value contract - null, bool, int, float, string,
 *    a shared object or a SharedArray. A plain array or a request object is refused with the
 *    remedy named, exactly as it would be on a channel;
 *  - **by-reference captures** (`use (&$x)`) and **declared statics** (`static $n = 0`) are
 *    refused outright: both are per-request slots, and a write to one after the fork is
 *    invisible to every other process with nothing to report the divergence.
 *
 * Static variables are read through the engine (z-engine's op-array view) rather than guessed
 * from the source: the table is where `use` values, by-reference captures and declared statics
 * all live, and the reference flag on a slot is what tells them apart.
 *
 * ## Invocation
 *
 * A receiver resolves a record - by name, or by an address that arrived in a value record -
 * and gets a `Closure` bound to the inherited object. Calls run entirely inside the calling
 * process: the run-time cache and the static-variable table the engine touches are per-process
 * copies from the moment anything writes them, which is what makes concurrent invocation in
 * several workers safe and what keeps Phase B (arena-resident closures) a separate problem -
 * see docs/closure-cloning.md.
 */
final class ClosureProvenance
{
    /**
     * 'SHMCLOSR' - the first word of the table, so a wrong address is refused, not read
     */
    public const int MAGIC = 0x53484D434C4F5352;

    public const int DEFAULT_CAPACITY = 64;

    /**
     * Longest closure name that fits inline in a record (bytes)
     */
    public const int MAX_NAME = 32;

    public const int HEADER_SIZE = 64;

    public const int RECORD_SIZE = 64;

    /**
     * Roots-directory name the table publishes itself under by default
     */
    public const string DEFAULT_ROOT = 'closures';

    private const int WORD_MAGIC       = 0;
    private const int WORD_CAPACITY    = 1;
    private const int WORD_COUNT       = 2;
    private const int WORD_CREATOR_PID = 3;
    private const int WORD_BARRIER_PID = 4;

    private const int RECORD_WORD_CLOSURE     = 0;
    private const int RECORD_WORD_FUNCTION    = 1;
    private const int RECORD_WORD_NAME_LENGTH = 2;
    private const int RECORD_WORD_BOUND_THIS  = 3;
    private const int RECORD_NAME_OFFSET      = 32;

    private readonly Arena $arena;

    private readonly int $capacity;

    private readonly int $stripe;

    /**
     * Closures this process registered, kept alive for the lifetime of the process
     *
     * The refcount pin below is what guarantees the object survives; this array is what
     * guarantees nothing ever wants to reclaim it in the first place.
     *
     * @var array<string, \Closure>
     */
    private array $registered = [];

    /**
     * Closures materialized in THIS process, keyed by record address
     *
     * @var array<int, \Closure>
     */
    private array $resolved = [];

    /**
     * Reverse index for the encode path: closure object address => record address
     *
     * @var array<int, int>
     */
    private array $byObject = [];

    private int $indexedRecords = 0;

    private bool $recoveredLock = false;

    private function __construct(
        private readonly ArenaAllocator $allocator,
        private readonly ?PersistentStore $store,
        private readonly int $address,
    ) {
        $this->arena = $allocator->arena();
        if (!$this->arena->contains($address, self::HEADER_SIZE)) {
            throw IpcException::notShared('closure table', $address);
        }
        if ($this->arena->readWord($address + self::WORD_MAGIC * 8) !== self::MAGIC) {
            throw ClosureProvenanceException::notARecord($address);
        }
        $this->capacity = $this->arena->readWord($address + self::WORD_CAPACITY * 8);
        $this->stripe   = $this->arena->stripeFor($address);
    }

    /**
     * Creates the closure table of a worker family - in the parent, before any fork
     *
     * @param int         $capacity Records reserved for the lifetime of the arena
     * @param string|null $name     Roots-directory name a child can find the table by
     */
    public static function create(
        ArenaAllocator $allocator,
        ?PersistentStore $store = null,
        int $capacity = self::DEFAULT_CAPACITY,
        ?string $name = self::DEFAULT_ROOT,
    ): self {
        if ($capacity <= 0) {
            throw IpcException::invalidCapacity('Closure table', $capacity);
        }
        $arena   = $allocator->arena();
        $address = $arena->allocate(self::HEADER_SIZE + $capacity * self::RECORD_SIZE, 64);

        // Kernel-zeroed memory already reads as an empty table; the magic is written last,
        // so a reader that finds it finds a complete header behind it
        $arena->writeWord($address + self::WORD_CAPACITY * 8, $capacity);
        $arena->writeWord($address + self::WORD_CREATOR_PID * 8, (int) getmypid());
        $arena->writeWord($address + self::WORD_MAGIC * 8, self::MAGIC);

        if ($name !== null) {
            $arena->putRoot($name, $address);
        }

        return new self($allocator, $store, $address);
    }

    /**
     * Binds the table another process created, by address
     */
    public static function attach(ArenaAllocator $allocator, ?PersistentStore $store, int $address): self
    {
        return new self($allocator, $store, $address);
    }

    /**
     * Binds the table published in the arena roots directory
     */
    public static function open(
        ArenaAllocator $allocator,
        ?PersistentStore $store = null,
        string $name = self::DEFAULT_ROOT,
    ): self {
        return new self($allocator, $store, $allocator->arena()->requireRoot($name));
    }

    /**
     * Address of the table header - what a child needs to attach()
     */
    public function address(): int
    {
        return $this->address;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Registered closures so far (a single aligned word: no lock, correction #2)
     */
    public function count(): int
    {
        return $this->arena->readWord($this->address + self::WORD_COUNT * 8);
    }

    /**
     * The process that mapped the arena and owns registration
     */
    public function creatorPid(): int
    {
        return $this->arena->readWord($this->address + self::WORD_CREATOR_PID * 8);
    }

    /**
     * Whether a stripe lock of this table was ever recovered from a worker that died holding it
     */
    public function wasLockRecovered(): bool
    {
        return $this->recoveredLock;
    }

    /**
     * Closes registration: from here on, code is fork material and nothing else
     *
     * The runtime calls this once, immediately before it forks its workers. The moment is
     * recorded in the ARENA rather than in this process, so a worker that boots its own view
     * of the table still sees a closed register and cannot talk itself into registering the
     * closures it compiled after the fork - which are exactly the unsafe ones.
     *
     * Idempotent: marking an already-marked barrier keeps the first process's mark.
     */
    public function markForkBarrier(): void
    {
        if ($this->barrierPid() !== 0) {
            return;
        }
        $this->arena->writeWord($this->address + self::WORD_BARRIER_PID * 8, (int) getmypid());
    }

    public function isForkBarrierPassed(): bool
    {
        return $this->barrierPid() !== 0;
    }

    /**
     * Pid of the process that marked the fork barrier, or 0 while registration is open
     */
    public function barrierPid(): int
    {
        return $this->arena->readWord($this->address + self::WORD_BARRIER_PID * 8);
    }

    /**
     * Makes one pre-fork closure shareable, under a name, and returns its record address
     *
     * The only way a closure ever becomes a legal cross-worker value. Call it from the process
     * that created the arena, before markForkBarrier() - both are enforced, and both refusals
     * are the mechanism working rather than a limitation to route around.
     *
     * @param string $name Up to MAX_NAME bytes; unique for the life of the arena
     *
     * @return int Address of the arena record - the eight bytes that travel to a worker
     */
    public function registerSharedClosure(string $name, \Closure $closure): int
    {
        $length = \strlen($name);
        if ($length === 0 || $length > self::MAX_NAME) {
            throw ClosureProvenanceException::invalidName($name, self::MAX_NAME);
        }
        if ($this->isForkBarrierPassed()) {
            throw ClosureProvenanceException::afterBarrier($name, $this->barrierPid());
        }
        $currentPid = (int) getmypid();
        if ($currentPid !== $this->creatorPid()) {
            throw ClosureProvenanceException::notCreator($name, $this->creatorPid(), $currentPid);
        }
        if ($this->addressOfName($name) !== null) {
            throw ClosureProvenanceException::duplicateName($name);
        }

        $boundThis = $this->assertShareableBinding($name, $closure);
        $this->assertShareableCaptures($name, $closure);

        $closureAddress  = $this->pinClosure($closure);
        $functionAddress = self::functionAddressOf($closureAddress);

        $slot = $this->claimSlot();
        $this->writeRecord($slot, $name, $closureAddress, $functionAddress, $boundThis);

        // Held for the lifetime of this process, so nothing ever asks to reclaim the object
        // whose address the whole family is about to inherit
        $this->registered[$name] = $closure;

        return $this->recordAddress($slot);
    }

    /**
     * Record address of a registered name, or null when nothing was registered under it
     */
    public function addressOfName(string $name): ?int
    {
        foreach ($this->records() as $slot => $record) {
            if ($record['name'] === $name) {
                return $this->recordAddress($slot);
            }
        }

        return null;
    }

    /**
     * Record address of a closure this family shares, or null when it is not registered
     *
     * The predicate the value codec asks before a closure may travel: a null answer means the
     * closure has no provenance here, and no amount of inspecting it can produce one.
     */
    public function addressOfClosure(\Closure $closure): ?int
    {
        $objectAddress = self::objectAddressOf($closure);
        $this->refreshIndex();

        return $this->byObject[$objectAddress] ?? null;
    }

    public function isShared(\Closure $closure): bool
    {
        return $this->addressOfClosure($closure) !== null;
    }

    /**
     * The closure registered under $name, ready to invoke in THIS process
     */
    public function closure(string $name): \Closure
    {
        $address = $this->addressOfName($name) ?? throw ClosureProvenanceException::unknownName($name);

        return $this->resolve($address);
    }

    /**
     * Materializes the closure a record describes - the receiving half of the exchange
     *
     * Everything that can refuse does so before the object is touched: the address must be a
     * record slot of this table, the record must carry a closure address, and the object there
     * must still be a `Closure` carrying the very `zend_function` the record witnessed. None of
     * that is the acceptance test (registration is), it is a bounds-and-integrity check on our
     * own bookkeeping - which is why a failure throws instead of invoking something plausible.
     *
     * @param int $address Record address from registerSharedClosure(), a value record or a
     *                     roots lookup - the same eight bytes in every process of the family
     */
    public function resolve(int $address): \Closure
    {
        if (isset($this->resolved[$address])) {
            return $this->resolved[$address];
        }
        $slot = $this->slotAt($address);
        if ($slot === null) {
            throw ClosureProvenanceException::notARecord($address);
        }
        $record = $this->readRecord($slot);
        if ($record['closure'] === 0) {
            throw ClosureProvenanceException::notARecord($address);
        }
        // Class entry first, zend_function second: the second reads THROUGH the object as a
        // zend_closure, which is only meaningful once the first has said that it is one
        $isIntact = self::isClosureObject($record['closure'])
            && self::functionAddressOf($record['closure']) === $record['function'];
        if (!$isIntact) {
            throw ClosureProvenanceException::recordDrifted($address);
        }

        return $this->resolved[$address] = self::closureAt($record['closure']);
    }

    /**
     * Every registered name, in registration order
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->records() as $record) {
            $names[] = $record['name'];
        }

        return $names;
    }

    /**
     * Bound $this of a registered closure as an arena address, 0 for an unbound one
     */
    public function boundThisAddressOf(string $name): int
    {
        return $this->recordNamed($name)['boundThis'];
    }

    /**
     * Where the closure object itself lives - deliberately NOT in the arena
     *
     * Phase A shares no closure memory at all: the object stays in the pages the family
     * inherited from the process that compiled it, and only the record vouching for it is
     * arena-resident. Exposed because that distinction is the whole design and is worth being
     * able to observe (see docs/closure-cloning.md for what moving it would take).
     */
    public function closureAddressOf(string $name): int
    {
        return $this->recordNamed($name)['closure'];
    }

    /**
     * @return array{name: string, closure: int, function: int, boundThis: int}
     */
    private function recordNamed(string $name): array
    {
        foreach ($this->records() as $record) {
            if ($record['name'] === $name) {
                return $record;
            }
        }

        throw ClosureProvenanceException::unknownName($name);
    }

    /**
     * @return int Arena address of a bound $this, or 0 when the closure is unbound
     */
    private function assertShareableBinding(string $name, \Closure $closure): int
    {
        $boundThis = (new \ReflectionFunction($closure))->getClosureThis();
        if ($boundThis === null) {
            return 0;
        }
        $address = $this->store?->addressOfInstance($boundThis);
        if ($address === null) {
            throw ClosureProvenanceException::boundThisNotShared($name, $boundThis::class);
        }

        return $address;
    }

    /**
     * Refuses every capture that would mean something different in the receiving worker
     *
     * Two passes, because they answer two different questions. The engine's static-variable
     * table says HOW a name is captured - a slot holding a reference is either `use (&$x)` or a
     * bound `static $n`, both per-request state - while native reflection says WHAT the
     * by-value captures are, which is the ordinary value contract every record on a channel
     * has to satisfy.
     */
    private function assertShareableCaptures(string $name, \Closure $closure): void
    {
        $used = (new \ReflectionFunction($closure))->getClosureUsedVariables();

        $closureEntry = new ClosureEntry($closure);
        $statics      = ReflectionFunction::fromCData($closureEntry->getRawFunction())->getStaticVariables();
        if ($statics !== null) {
            foreach ($statics as $variable => $value) {
                $variable = (string) $variable;
                if (!\array_key_exists($variable, $used)) {
                    throw ClosureProvenanceException::carriesStaticState($name, $variable);
                }
                if (($value->getType() & 0xFF) === ReflectionValue::IS_REFERENCE) {
                    throw ClosureProvenanceException::capturedByReference($name, $variable);
                }
            }
        }

        foreach ($used as $variable => $value) {
            $this->assertShareableCapture($value);
        }
    }

    /**
     * The value contract, applied to one captured value without allocating anything
     *
     * Deliberately NOT ValueCodec::encode(): encoding a string interns it into the arena, and a
     * capture is not a value being sent - it is a value being vouched for. The answers are the
     * same, and so are the exceptions, so a rejected capture reads exactly like a rejected send.
     */
    private function assertShareableCapture(mixed $value): void
    {
        match (true) {
            $value === null,
            \is_bool($value),
            \is_int($value),
            \is_float($value),
            \is_string($value)    => null,
            $value instanceof SharedArray => null,
            $value instanceof \Closure    => throw NotShareableValueException::closure(),
            \is_array($value)     => throw NotShareableValueException::plainArray(),
            \is_resource($value)  => throw NotShareableValueException::resource(),
            \is_object($value)    => $this->store?->addressOfInstance($value) !== null
                ? null
                : throw NotShareableValueException::foreignObject($value::class),
            default               => throw NotShareableValueException::unsupportedType(\gettype($value)),
        };
    }

    /**
     * Pins the closure object so no process of the family can ever reclaim it
     *
     * The same pin every persistent clone carries (`PIN_BASELINE`), for the same reason: the
     * address is about to be inherited by processes this one knows nothing about, and a
     * refcount that reaches zero in any of them would free memory the rest are still reading.
     * The pin is written BEFORE the fork, so every worker inherits an object that cannot be
     * released; request shutdown of the creating process still tears it down with everything
     * else, which is exactly when the family is gone anyway.
     *
     * @return int Address of the closure's zend_object
     */
    private function pinClosure(\Closure $closure): int
    {
        $value = new ReflectionValue($closure);

        try {
            $rawObject               = $value->getRawObject();
            $rawObject->gc->refcount = PersistentObjectFactory::PIN_BASELINE;

            return Core::addressOf($rawObject);
        } finally {
            $value->release();
        }
    }

    /**
     * Reserves the next record slot under the table's stripe lock
     */
    private function claimSlot(): int
    {
        $countAddress = $this->address + self::WORD_COUNT * 8;

        $recovered = $this->arena->lockStripe($this->stripe);

        $slot = $this->arena->readWord($countAddress);
        $fits = $slot < $this->capacity;
        if ($fits) {
            $this->arena->writeWord($countAddress, $slot + 1);
        }

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        if (!$fits) {
            throw ClosureProvenanceException::tableFull($this->capacity);
        }

        return $slot;
    }

    private function writeRecord(int $slot, string $name, int $closure, int $function, int $boundThis): void
    {
        $record = $this->recordAddress($slot);

        $recovered = $this->arena->lockStripe($this->stripe);

        $this->arena->writeBytes($record + self::RECORD_NAME_OFFSET, $name);
        $this->arena->writeWord($record + self::RECORD_WORD_NAME_LENGTH * 8, \strlen($name));
        $this->arena->writeWord($record + self::RECORD_WORD_BOUND_THIS * 8, $boundThis);
        $this->arena->writeWord($record + self::RECORD_WORD_FUNCTION * 8, $function);
        // Written last: a reader that sees a closure address sees a finished record behind it
        $this->arena->writeWord($record + self::RECORD_WORD_CLOSURE * 8, $closure);

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;
    }

    /**
     * @return array{name: string, closure: int, function: int, boundThis: int}
     */
    private function readRecord(int $slot): array
    {
        $record = $this->recordAddress($slot);

        $recovered = $this->arena->lockStripe($this->stripe);

        $closure   = $this->arena->readWord($record + self::RECORD_WORD_CLOSURE * 8);
        $function  = $this->arena->readWord($record + self::RECORD_WORD_FUNCTION * 8);
        $length    = $this->arena->readWord($record + self::RECORD_WORD_NAME_LENGTH * 8);
        $boundThis = $this->arena->readWord($record + self::RECORD_WORD_BOUND_THIS * 8);
        $name      = $length > 0 ? $this->arena->readBytes($record + self::RECORD_NAME_OFFSET, $length) : '';

        $this->arena->unlockStripe($this->stripe);

        $this->recoveredLock = $this->recoveredLock || $recovered;

        return ['name' => $name, 'closure' => $closure, 'function' => $function, 'boundThis' => $boundThis];
    }

    /**
     * Every written record, slot => record
     *
     * @return array<int, array{name: string, closure: int, function: int, boundThis: int}>
     */
    private function records(): array
    {
        $records = [];
        $count   = min($this->count(), $this->capacity);
        for ($slot = 0; $slot < $count; $slot++) {
            $record = $this->readRecord($slot);
            if ($record['closure'] !== 0) {
                $records[$slot] = $record;
            }
        }

        return $records;
    }

    /**
     * Keeps the encode-path index in step with the table
     *
     * Registration is a pre-fork, single-process affair, so the table is immutable by the time
     * any worker asks - one scan per process is normally the whole cost, and a changed count
     * is the only thing that can invalidate it.
     */
    private function refreshIndex(): void
    {
        $count = min($this->count(), $this->capacity);
        if ($count === $this->indexedRecords) {
            return;
        }
        $this->byObject = [];
        foreach ($this->records() as $slot => $record) {
            $this->byObject[$record['closure']] = $this->recordAddress($slot);
        }
        $this->indexedRecords = $count;
    }

    private function recordAddress(int $slot): int
    {
        return $this->address + self::HEADER_SIZE + $slot * self::RECORD_SIZE;
    }

    /**
     * Slot number an address identifies, or null when it is not a record of this table
     */
    private function slotAt(int $address): ?int
    {
        $offset = $address - $this->address - self::HEADER_SIZE;
        if ($offset < 0 || $offset % self::RECORD_SIZE !== 0) {
            return null;
        }
        $slot = intdiv($offset, self::RECORD_SIZE);

        return $slot < min($this->count(), $this->capacity) ? $slot : null;
    }

    /**
     * Whether the object at $address is a Closure in THIS process's class table
     *
     * Not an acceptance test and never used as one - acceptance is registration (correction
     * #8), which is precisely the finding that a stale address can hold a valid Closure of a
     * different function. This is the cheap structural guard that runs before the record's
     * zend_function witness is read through the object.
     */
    private static function isClosureObject(int $address): bool
    {
        $classEntry = Core::pointerAtAddress(zend_object::class, $address)->ce;
        if ($classEntry === null) {
            return false;
        }
        $closureClass = Core::$executor->classTable->find('closure');
        if ($closureClass === null) {
            return false;
        }

        return Core::addressOf($closureClass->getRawClass()) === Core::addressOf($classEntry);
    }

    /**
     * Address of the zend_function embedded in the closure object at $address
     */
    private static function functionAddressOf(int $address): int
    {
        $closureEntry = ClosureEntry::fromCData(Core::pointerAtAddress(zend_closure::class, $address));

        return Core::addressOf($closureEntry->getRawFunction());
    }

    private static function objectAddressOf(\Closure $closure): int
    {
        $value = new ReflectionValue($closure);

        try {
            return Core::addressOf($value->getRawObject());
        } finally {
            $value->release();
        }
    }

    /**
     * Materializes the PHP value of the closure object living at $address (+1 ref, pinned)
     */
    private static function closureAt(int $address): \Closure
    {
        $rawObject = Core::pointerAtAddress(zend_object::class, $address);
        $value     = ReflectionValue::newEntry(ReflectionValue::IS_OBJECT, $rawObject[0]);
        $value->getNativeValue($instance);
        $value->release();

        \assert($instance instanceof \Closure);

        return $instance;
    }
}
