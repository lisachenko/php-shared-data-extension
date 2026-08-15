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

namespace Lisachenko\SharedData\Shm;

use FFI;
use FFI\CData;

/**
 * One fixed-size region of fork-shared memory with a bump allocator in front of it
 *
 * The arena is the foundation of cross-process object sharing: a single
 * `mmap(NULL, size, PROT_READ|PROT_WRITE, MAP_SHARED|MAP_ANONYMOUS, -1, 0)` region created
 * BEFORE any worker is forked. Every child inherits the mapping at the very same virtual
 * address, so an address handed to a sibling over a pipe as eight raw bytes means the same
 * thing there - which is what lets whole PHP values be exchanged without serialization.
 *
 * ## Why it must be created pre-fork
 *
 * `MAP_SHARED|MAP_ANONYMOUS` memory is shared with the CHILDREN of the process that
 * created it (and with its own later self), never with unrelated processes. Two workers
 * see the same bytes because they descend from one parent that mapped them; a process that
 * maps its own arena after the fork gets private memory that happens to look identical.
 *
 * ## Layout
 *
 * ```text
 *   0        header words   magic, layout version, size, cursor, creator pid,
 *                           mutex slot size, roots capacity
 *   128      mutex bank     64 slots x 64 bytes, PTHREAD_PROCESS_SHARED + ROBUST
 *                           slot 0 = allocator, slot 1 = roots directory, 2.. = stripes
 *   4224     roots dir      64 entries x 64 bytes: hash, address, name length, name[40]
 *   16384    payload        bump-allocated, cursor grows upwards, never shrinks
 * ```
 *
 * The mutex bank reserves 64 bytes per `pthread_mutex_t` (40 on x86-64/arm64 glibc,
 * measured at runtime by Libc::probeMutexSize() rather than assumed) so a platform with a
 * bigger mutex is a clean exception instead of neighbouring slots overlapping.
 *
 * ## Allocation model: bump, and leak until teardown
 *
 * `allocate()` moves one cursor forward under the allocator mutex and never moves it back.
 * There is no free list and no per-block header: blocks are released when the arena dies,
 * which is when the CREATING process exits (only it unmaps - a child unmapping the region
 * would pull memory out from under its parent and its siblings). This is deliberate for v1:
 * a shared free list needs cross-process reachability accounting that nothing here can
 * provide yet. Exhaustion is therefore a normal, typed outcome - ArenaException::exhausted().
 *
 * ## Locking rules
 *
 * While ANY arena mutex is held, only word loads and stores through the cached views
 * below are allowed: no engine call that can allocate, no userland callback, nothing that
 * can throw. Every method here obeys that - the critical sections are single aligned
 * 64-bit updates, which is also why a lock recovered from a died owner (`EOWNERDEAD`) can
 * simply be declared consistent again: a torn state is not reachable.
 *
 * ## Public surface
 *
 * No method returns `FFI\CData`. Callers see integers (addresses, sizes) and strings; the
 * pointer views are private, created once per process at map time, and reused - creating
 * them per call would both leak FFI type structures and violate the locking rule above.
 */
final class Arena
{
    /**
     * "SHMARENA" in ASCII, the first word of every arena
     */
    public const int MAGIC = 0x53484D4152454E41;

    /**
     * Version of the layout described above; bumped whenever any offset here moves
     */
    public const int LAYOUT_VERSION = 1;

    /**
     * Header size, and therefore the offset of the first allocatable byte
     */
    public const int HEADER_SIZE = 16384;

    public const int MUTEX_OFFSET     = 128;
    public const int MUTEX_COUNT      = 64;
    public const int MUTEX_SLOT_SIZE  = 64;
    public const int MUTEX_SIZE_FLOOR = 40;

    /**
     * Mutex 0 serializes the bump cursor, mutex 1 the roots directory; the rest are free
     * for consumers (stripe locks over data structures living in the arena)
     */
    public const int ALLOCATOR_MUTEX = 0;
    public const int ROOTS_MUTEX     = 1;
    public const int FIRST_STRIPE    = 2;

    public const int ROOTS_OFFSET     = 4224;
    public const int ROOT_CAPACITY    = 64;
    public const int ROOT_ENTRY_SIZE  = 64;
    public const int ROOT_ENTRY_WORDS = 8;
    public const int ROOT_NAME_SIZE   = 40;

    /**
     * Default arena size, overridable through the SHARED_DATA_ARENA_SIZE environment
     * variable (plain bytes, or a K/M/G suffix)
     */
    public const int DEFAULT_SIZE = 64 * 1024 * 1024;

    public const string SIZE_ENV = 'SHARED_DATA_ARENA_SIZE';

    /**
     * Word indexes into the header
     */
    private const int WORD_MAGIC          = 0;
    private const int WORD_LAYOUT_VERSION = 1;
    private const int WORD_SIZE           = 2;
    private const int WORD_CURSOR         = 3;
    private const int WORD_CREATOR_PID    = 4;
    private const int WORD_MUTEX_SIZE     = 5;
    private const int WORD_ROOT_CAPACITY  = 6;

    private const int MAX_ALIGNMENT = 4096;

    /**
     * Offsets of the fields inside one roots-directory entry, in words
     */
    private const int ROOT_WORD_HASH    = 0;
    private const int ROOT_WORD_ADDRESS = 1;
    private const int ROOT_WORD_LENGTH  = 2;
    private const int ROOT_WORD_NAME    = 3;

    /**
     * char* over the whole mapping - the anchor every other view is derived from
     */
    private CData $base;

    /**
     * uint64_t* over the whole mapping: header fields and roots entries are word indexes
     * into this one view, so a critical section never has to create a CData
     */
    private CData $words;

    /**
     * char* at each mutex slot, materialized on first use and then reused forever
     *
     * @var array<int, CData>
     */
    private array $mutexes = [];

    private bool $released = false;

    private function __construct(
        private readonly int $baseAddress,
        private readonly int $size,
        private readonly int $creatorPid,
    ) {
    }

    /**
     * Maps a new arena; call this ONCE, before any worker is forked
     *
     * @param int|null $size Total size in bytes, header included; defaults to the
     *                       SHARED_DATA_ARENA_SIZE environment variable or 64 MB
     */
    public static function create(?int $size = null): self
    {
        $size ??= self::configuredSize();
        if ($size <= self::HEADER_SIZE || $size % 4096 !== 0) {
            throw ArenaException::invalidSize($size);
        }

        $mutexSize = Libc::probeMutexSize();
        if ($mutexSize > self::MUTEX_SLOT_SIZE) {
            throw ArenaException::mutexSlotTooSmall($mutexSize, self::MUTEX_SLOT_SIZE);
        }

        $mapping = Libc::mapShared($size);
        $arena   = new self(Libc::addressOf($mapping), $size, getmypid());
        $arena->bindViews($mapping);

        // The mapping is zero-filled by the kernel, so every roots entry already reads as
        // empty and the cursor only has to be lifted over the header
        $arena->words[self::WORD_MAGIC]          = self::MAGIC;
        $arena->words[self::WORD_LAYOUT_VERSION] = self::LAYOUT_VERSION;
        $arena->words[self::WORD_SIZE]           = $size;
        $arena->words[self::WORD_CURSOR]         = self::HEADER_SIZE;
        $arena->words[self::WORD_CREATOR_PID]    = $arena->creatorPid;
        $arena->words[self::WORD_MUTEX_SIZE]     = $mutexSize;
        $arena->words[self::WORD_ROOT_CAPACITY]  = self::ROOT_CAPACITY;

        for ($index = 0; $index < self::MUTEX_COUNT; $index++) {
            Libc::initSharedMutex($arena->mutexAt($index));
        }

        // Only the creator ever unmaps - children inherit this shutdown function through
        // fork() and it has to stay a no-op there
        register_shutdown_function(static function () use ($arena): void {
            $arena->destroy();
        });

        return $arena;
    }

    /**
     * Reads the configured arena size (bytes, or a K/M/G suffix) from the environment
     */
    public static function configuredSize(): int
    {
        $configured = getenv(self::SIZE_ENV);
        if ($configured === false || trim($configured) === '') {
            return self::DEFAULT_SIZE;
        }
        $configured = strtoupper(trim($configured));
        if (preg_match('/^(\d+)([KMG]?)B?$/', $configured, $matches) !== 1) {
            throw ArenaException::invalidSize(0);
        }

        $multiplier = match ($matches[2]) {
            'K'     => 1024,
            'M'     => 1024 * 1024,
            'G'     => 1024 * 1024 * 1024,
            default => 1,
        };

        return (int) $matches[1] * $multiplier;
    }

    /**
     * Address of the first byte of the mapping (identical in every forked child)
     */
    public function baseAddress(): int
    {
        return $this->baseAddress;
    }

    /**
     * Total size of the mapping, header included
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Bytes available to allocate() when the arena is empty
     */
    public function capacity(): int
    {
        return $this->size - self::HEADER_SIZE;
    }

    /**
     * High-water mark: payload bytes handed out so far, alignment padding included
     *
     * The number never falls (blocks are never returned), which makes it the honest gauge
     * for "does this workload plateau?" in soak runs.
     */
    public function watermark(): int
    {
        $this->assertLive();

        return $this->cursor() - self::HEADER_SIZE;
    }

    /**
     * Bytes still allocatable
     */
    public function remaining(): int
    {
        $this->assertLive();

        return $this->size - $this->cursor();
    }

    /**
     * Whether this process is the one that created the arena (and will unmap it)
     */
    public function isCreator(): bool
    {
        return getmypid() === $this->creatorPid;
    }

    /**
     * Process id of the creator - the only process allowed to unmap
     */
    public function creatorPid(): int
    {
        return $this->creatorPid;
    }

    /**
     * Measured size of this platform's pthread_mutex_t, as recorded in the header
     */
    public function mutexSize(): int
    {
        $this->assertLive();

        return (int) $this->words[self::WORD_MUTEX_SIZE];
    }

    /**
     * Number of mutex slots consumers may use as stripe locks
     */
    public function stripeCount(): int
    {
        return self::MUTEX_COUNT - self::FIRST_STRIPE;
    }

    /**
     * Hands out $size bytes of arena memory, aligned to $align, and returns their address
     *
     * Safe to call from any process that inherited the arena: the cursor lives in shared
     * memory and is moved under the shared allocator mutex, so two children allocating at
     * the same moment get disjoint blocks.
     *
     * @param int $size  Bytes to reserve (must be positive)
     * @param int $align Power-of-two alignment of the returned address, at most 4096
     *
     * @return int Absolute address of the block, valid in every process of the family
     */
    public function allocate(int $size, int $align = 16): int
    {
        $this->assertLive();
        if ($size <= 0) {
            throw ArenaException::invalidSize($size);
        }
        if ($align <= 0 || $align > self::MAX_ALIGNMENT || ($align & ($align - 1)) !== 0) {
            throw ArenaException::invalidAlignment($align);
        }

        // Everything that can throw or allocate is done BEFORE the lock is taken; the
        // critical section below is two word accesses and nothing else
        $mutex = $this->mutexAt(self::ALLOCATOR_MUTEX);

        Libc::lockMutex($mutex, self::ALLOCATOR_MUTEX);

        $cursor  = (int) $this->words[self::WORD_CURSOR];
        $aligned = ($cursor + $align - 1) & ~($align - 1);
        $next    = $aligned + $size;
        $fits    = $next <= $this->size;
        if ($fits) {
            $this->words[self::WORD_CURSOR] = $next;
        }

        Libc::unlockMutex($mutex, self::ALLOCATOR_MUTEX);

        if (!$fits) {
            throw ArenaException::exhausted($size, $this->size - $cursor);
        }

        return $this->baseAddress + $aligned;
    }

    /**
     * Publishes an address under a name every process of the family can look up
     *
     * The directory is a fixed-size open-addressing table: it is how a child finds the
     * structures the parent placed in the arena (the registry tables, an IPC ring, ...)
     * without inheriting a single PHP variable. Re-registering a name overwrites it.
     */
    public function putRoot(string $name, int $address): void
    {
        $this->assertLive();
        $nameWords = $this->encodeRootName($name);
        $hash      = self::hashOf($name);
        $length    = \strlen($name);

        $mutex = $this->mutexAt(self::ROOTS_MUTEX);

        Libc::lockMutex($mutex, self::ROOTS_MUTEX);

        $slot = $this->probeRoot($hash, $nameWords, $length);
        if ($slot !== null) {
            $entry = $this->rootWordIndex($slot);

            $this->words[$entry + self::ROOT_WORD_HASH]    = $hash;
            $this->words[$entry + self::ROOT_WORD_ADDRESS] = $address;
            $this->words[$entry + self::ROOT_WORD_LENGTH]  = $length;
            foreach ($nameWords as $offset => $word) {
                $this->words[$entry + self::ROOT_WORD_NAME + $offset] = $word;
            }
        }

        Libc::unlockMutex($mutex, self::ROOTS_MUTEX);

        if ($slot === null) {
            throw ArenaException::rootsFull($name);
        }
    }

    /**
     * Looks a named root up, or null when nothing was published under that name
     */
    public function findRoot(string $name): ?int
    {
        $this->assertLive();
        $nameWords = $this->encodeRootName($name);
        $hash      = self::hashOf($name);
        $length    = \strlen($name);

        // Reads need no lock: an entry is written hash-last... but a torn read can still
        // see a half-written name, so the read side takes the same mutex. It is taken for
        // a handful of word loads, and lookups happen at boot, not in hot paths
        $mutex = $this->mutexAt(self::ROOTS_MUTEX);

        Libc::lockMutex($mutex, self::ROOTS_MUTEX);

        $address = null;
        $slot    = $this->probeRoot($hash, $nameWords, $length);
        if ($slot !== null) {
            $entry = $this->rootWordIndex($slot);
            if ($this->words[$entry + self::ROOT_WORD_HASH] !== 0) {
                $address = (int) $this->words[$entry + self::ROOT_WORD_ADDRESS];
            }
        }

        Libc::unlockMutex($mutex, self::ROOTS_MUTEX);

        return $address;
    }

    /**
     * Same as findRoot(), but a missing name is an error rather than a null
     */
    public function requireRoot(string $name): int
    {
        return $this->findRoot($name) ?? throw ArenaException::unknownRoot($name);
    }

    /**
     * Every published root, name => address
     *
     * @return array<string, int>
     */
    public function roots(): array
    {
        $this->assertLive();

        $mutex = $this->mutexAt(self::ROOTS_MUTEX);

        Libc::lockMutex($mutex, self::ROOTS_MUTEX);

        /** @var array<int, array{0: int, 1: int, 2: list<int>}> $raw */
        $raw = [];
        for ($slot = 0; $slot < self::ROOT_CAPACITY; $slot++) {
            $entry = $this->rootWordIndex($slot);
            if ($this->words[$entry + self::ROOT_WORD_HASH] === 0) {
                continue;
            }
            $nameWords = [];
            for ($word = 0; $word < self::ROOT_NAME_SIZE / 8; $word++) {
                $nameWords[] = (int) $this->words[$entry + self::ROOT_WORD_NAME + $word];
            }
            $raw[] = [
                (int) $this->words[$entry + self::ROOT_WORD_ADDRESS],
                (int) $this->words[$entry + self::ROOT_WORD_LENGTH],
                $nameWords,
            ];
        }

        Libc::unlockMutex($mutex, self::ROOTS_MUTEX);

        $roots = [];
        foreach ($raw as [$address, $length, $nameWords]) {
            $roots[self::decodeRootName($nameWords, $length)] = $address;
        }

        return $roots;
    }

    /**
     * Takes one of the consumer stripe mutexes (indexes FIRST_STRIPE .. MUTEX_COUNT - 1)
     *
     * Only memory operations are allowed until the matching unlockStripe() - see the class
     * docblock. The return value reports whether the lock was recovered from a process that
     * died holding it, in which case the guarded structure has to be checked by the caller.
     *
     * @return bool Whether the previous owner died holding this lock
     */
    public function lockStripe(int $index): bool
    {
        return Libc::lockMutex($this->stripeAt($index), $index);
    }

    /**
     * @return bool Whether the lock was taken; false means somebody else holds it
     */
    public function tryLockStripe(int $index): bool
    {
        return Libc::tryLockMutex($this->stripeAt($index), $index);
    }

    public function unlockStripe(int $index): void
    {
        Libc::unlockMutex($this->stripeAt($index), $index);
    }

    /**
     * Verifies that the mapping still is the arena this build knows how to read
     *
     * Cheap, and the natural first thing a recovering worker does: the magic proves the
     * region is an arena at all (rather than a stale address or a mapping that was replaced),
     * and the layout version proves the offsets of the mutex bank and the roots directory
     * are the ones this build compiles against. A mismatch is a hard failure - reading a
     * foreign layout would mean locking bytes that are somebody else's data.
     */
    public function assertIntact(): void
    {
        $this->assertLive();

        $magic = (int) $this->words[self::WORD_MAGIC];
        if ($magic !== self::MAGIC) {
            throw ArenaException::notAnArena($magic);
        }
        $version = (int) $this->words[self::WORD_LAYOUT_VERSION];
        if ($version !== self::LAYOUT_VERSION) {
            throw ArenaException::layoutMismatch($version, self::LAYOUT_VERSION);
        }
    }

    /**
     * Whether a whole address range lies inside this arena's payload
     *
     * The bounds check behind every "is this structure still shared?" question: an engine
     * data block that has been reallocated into a worker's private heap answers false, and
     * that pointer change is the ONLY observable symptom of the resize - the engine writes
     * the new address into the shared struct before it aborts, so surviving siblings would
     * otherwise read plausible garbage with no signal at all.
     */
    public function contains(int $address, int $length = 1): bool
    {
        if ($this->released || $length < 0) {
            return false;
        }
        $offset = $address - $this->baseAddress;

        return $offset >= self::HEADER_SIZE && $offset + $length <= $this->size;
    }

    /**
     * Reads one aligned 64-bit word of arena payload
     */
    public function readWord(int $address): int
    {
        $this->assertRange($address, 8);
        if (($address & 7) !== 0) {
            throw ArenaException::misalignedAddress($address);
        }

        return (int) $this->words[($address - $this->baseAddress) >> 3];
    }

    /**
     * Writes one aligned 64-bit word of arena payload
     */
    public function writeWord(int $address, int $value): void
    {
        $this->assertRange($address, 8);
        if (($address & 7) !== 0) {
            throw ArenaException::misalignedAddress($address);
        }

        $this->words[($address - $this->baseAddress) >> 3] = $value;
    }

    /**
     * Copies $length bytes of arena payload out as a PHP string
     */
    public function readBytes(int $address, int $length): string
    {
        $this->assertRange($address, $length);

        return FFI::string($this->pointerAt($address - $this->baseAddress), $length);
    }

    /**
     * Copies a PHP string into arena payload; returns the number of bytes written
     */
    public function writeBytes(int $address, string $bytes): int
    {
        $length = \strlen($bytes);
        $this->assertRange($address, $length);
        if ($length > 0) {
            FFI::memcpy($this->pointerAt($address - $this->baseAddress), $bytes, $length);
        }

        return $length;
    }

    /**
     * Unmaps the arena - the creating process only, and only once
     *
     * A child calling this is a deliberate no-op rather than an error: the shutdown
     * function armed at create() is inherited by every fork, and a child unmapping the
     * region would tear the arena out from under its parent and siblings. A child's own
     * copy of the mapping goes away with the process anyway.
     */
    public function destroy(): void
    {
        if ($this->released || !$this->isCreator()) {
            return;
        }
        $this->released = true;
        $this->mutexes  = [];

        Libc::unmap($this->base, $this->size);
    }

    /**
     * Binds the two pointer views over a fresh mapping (once per process, at map time)
     */
    private function bindViews(CData $mapping): void
    {
        $this->base  = $mapping;
        $this->words = Libc::ffi()->cast('uint64_t *', $mapping);
    }

    private function cursor(): int
    {
        return (int) $this->words[self::WORD_CURSOR];
    }

    /**
     * char* at a byte offset into the mapping
     */
    private function pointerAt(int $offset): CData
    {
        return FFI::addr($this->base[$offset]);
    }

    /**
     * char* of one mutex slot, materialized once and cached for the process lifetime
     */
    private function mutexAt(int $index): CData
    {
        return $this->mutexes[$index] ??= $this->pointerAt(self::MUTEX_OFFSET + $index * self::MUTEX_SLOT_SIZE);
    }

    private function stripeAt(int $index): CData
    {
        $this->assertLive();
        if ($index < self::FIRST_STRIPE || $index >= self::MUTEX_COUNT) {
            throw ArenaException::invalidMutexIndex($index);
        }

        return $this->mutexAt($index);
    }

    /**
     * Word index of the first word of roots-directory slot $slot
     */
    private function rootWordIndex(int $slot): int
    {
        return (self::ROOTS_OFFSET + $slot * self::ROOT_ENTRY_SIZE) >> 3;
    }

    /**
     * Finds the slot a name lives in, or the first free slot it may be written to
     *
     * Called with the roots mutex held: word loads only, no allocation, no throwing.
     *
     * @param list<int> $nameWords
     *
     * @return int|null Slot index, or null when the table is full
     */
    private function probeRoot(int $hash, array $nameWords, int $length): ?int
    {
        $start = ($hash & PHP_INT_MAX) % self::ROOT_CAPACITY;
        for ($probe = 0; $probe < self::ROOT_CAPACITY; $probe++) {
            $slot  = ($start + $probe) % self::ROOT_CAPACITY;
            $entry = $this->rootWordIndex($slot);
            if ($this->words[$entry + self::ROOT_WORD_HASH] === 0) {
                return $slot;
            }
            if ($this->words[$entry + self::ROOT_WORD_HASH] !== $hash) {
                continue;
            }
            if ($this->words[$entry + self::ROOT_WORD_LENGTH] !== $length) {
                continue;
            }
            $same = true;
            foreach ($nameWords as $offset => $word) {
                if ($this->words[$entry + self::ROOT_WORD_NAME + $offset] !== $word) {
                    $same = false;

                    break;
                }
            }
            if ($same) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Packs a root name into the five words the directory entry stores it in
     *
     * Packing happens OUTSIDE the roots mutex on purpose: the critical section may only
     * move words around, and a name comparison done word-wise needs no string handling
     * inside the lock at all.
     *
     * @return list<int>
     */
    private function encodeRootName(string $name): array
    {
        if (\strlen($name) > self::ROOT_NAME_SIZE) {
            throw ArenaException::rootNameTooLong($name);
        }
        $padded = str_pad($name, self::ROOT_NAME_SIZE, "\0");

        $words = [];
        for ($offset = 0; $offset < self::ROOT_NAME_SIZE; $offset += 8) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('P', substr($padded, $offset, 8));
            $words[]  = $unpacked[1];
        }

        return $words;
    }

    /**
     * @param list<int> $words
     */
    private static function decodeRootName(array $words, int $length): string
    {
        $name = '';
        foreach ($words as $word) {
            $name .= pack('P', $word);
        }

        return substr($name, 0, $length);
    }

    /**
     * Stable, non-zero hash of a root name (zero marks a free directory slot)
     */
    private static function hashOf(string $name): int
    {
        return crc32($name) | 1;
    }

    /**
     * Rejects an address range that is not inside this arena's PAYLOAD
     *
     * The header is deliberately excluded: cursor, mutexes and the roots directory are the
     * arena's own bookkeeping and are only ever touched by the methods above.
     */
    private function assertRange(int $address, int $length): void
    {
        $this->assertLive();
        $offset = $address - $this->baseAddress;
        if ($length < 0 || $offset < self::HEADER_SIZE || $offset + $length > $this->size) {
            throw ArenaException::outOfBounds($address, $length);
        }
    }

    private function assertLive(): void
    {
        if ($this->released) {
            throw ArenaException::released();
        }
    }
}
