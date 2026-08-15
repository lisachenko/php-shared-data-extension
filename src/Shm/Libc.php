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
 * The package's own, minimal binding to the two libc facilities the arena is built on
 *
 * `FFI::cdef($code, null)` declares symbols without naming a shared object: on Linux the
 * lookup goes through `RTLD_DEFAULT`, which resolves against the already-loaded libc of
 * the running interpreter (same technique z-engine uses to reach the engine's own
 * symbols). Nothing is dlopen()ed, no header is generated, and z-engine's generated
 * engine definitions are left completely alone - the arena is deliberately NOT an engine
 * structure, so it has no business in engine headers.
 *
 * Two facilities, and only these two:
 *
 *  - `mmap`/`munmap` with `MAP_SHARED|MAP_ANONYMOUS`, which is what makes one region of
 *    memory visible at the SAME address in every process forked from the creator;
 *  - `pthread_mutex_*` with `PTHREAD_PROCESS_SHARED` and `PTHREAD_MUTEX_ROBUST`
 *    attributes, the only cross-process synchronization primitive reachable from PHP
 *    (FFI offers no atomics, no CAS and no fences). ROBUST is what keeps a killed worker
 *    from wedging the whole pool: the next locker is told the owner died (`EOWNERDEAD`)
 *    and may declare the state consistent again.
 *
 * All constants below are the Linux/x86-64 (and arm64 - they agree) ABI values. The class
 * is internal: it hands out `CData`, which no public API of this package ever does.
 *
 * @internal
 */
final class Libc
{
    public const int PROT_READ  = 0x1;
    public const int PROT_WRITE = 0x2;

    public const int MAP_SHARED    = 0x01;
    public const int MAP_ANONYMOUS = 0x20;

    public const int PTHREAD_PROCESS_SHARED = 1;
    public const int PTHREAD_MUTEX_ROBUST   = 1;

    /**
     * The errno values the mutex calls answer with that are not failures
     */
    public const int EBUSY           = 16;
    public const int EOWNERDEAD      = 130;
    public const int ENOTRECOVERABLE = 131;

    /**
     * Size of the scratch buffer a pthread_mutexattr_t is built in (it is 4 bytes on
     * glibc; 64 is "generously more than any libc will ever need" and costs one
     * request-lifetime allocation per process)
     */
    private const int ATTR_BUFFER_SIZE = 64;

    /**
     * Byte pattern the mutex-size probe paints its buffer with before pthread_mutex_init()
     *
     * Any value that is not a byte a fresh mutex may legitimately contain works; 0xAA is
     * the traditional "definitely not zero, definitely not a pointer" filler.
     */
    private const int PROBE_FILL = 0xAA;

    private const int PROBE_BUFFER_SIZE = 512;

    private static ?FFI $ffi = null;

    private static ?int $mutexSize = null;

    private function __construct()
    {
    }

    /**
     * Binds (once per process) the libc symbols the arena needs
     */
    public static function ffi(): FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            throw ArenaException::unsupportedPlatform(PHP_OS_FAMILY);
        }

        try {
            // No library name: RTLD_DEFAULT resolves these against the loaded libc
            $ffi = FFI::cdef(
                <<<'C'
                    void *mmap(void *addr, size_t length, int prot, int flags, int fd, int64_t offset);
                    int munmap(void *addr, size_t length);
                    int pthread_mutexattr_init(void *attr);
                    int pthread_mutexattr_setpshared(void *attr, int pshared);
                    int pthread_mutexattr_setrobust(void *attr, int robustness);
                    int pthread_mutexattr_destroy(void *attr);
                    int pthread_mutex_init(void *mutex, void *attr);
                    int pthread_mutex_destroy(void *mutex);
                    int pthread_mutex_lock(void *mutex);
                    int pthread_mutex_trylock(void *mutex);
                    int pthread_mutex_unlock(void *mutex);
                    int pthread_mutex_consistent(void *mutex);
                    C,
                null,
            );
        } catch (FFI\Exception $exception) {
            throw ArenaException::libcUnavailable($exception->getMessage());
        }

        return self::$ffi = $ffi;
    }

    /**
     * Maps a fresh region of shared anonymous memory, visible to every later fork
     *
     * @return CData char* at the start of the mapping
     */
    public static function mapShared(int $size): CData
    {
        $ffi     = self::ffi();
        $mapping = $ffi->mmap(
            null,
            $size,
            self::PROT_READ | self::PROT_WRITE,
            self::MAP_SHARED | self::MAP_ANONYMOUS,
            -1,
            0,
        );
        \assert($mapping instanceof CData);

        // MAP_FAILED is (void *) -1; a null return is impossible for a successful mmap
        if (FFI::isNull($mapping) || self::addressOf($mapping) === -1) {
            throw ArenaException::mappingFailed($size);
        }

        return $ffi->cast('char *', $mapping);
    }

    public static function unmap(CData $mapping, int $size): void
    {
        self::ffi()->munmap($mapping, $size);
    }

    /**
     * Numeric address of a pointer
     *
     * `FFI::cast('uintptr_t', $pointer)` cannot be used for this: on a pointer CData it
     * reinterprets the POINTEE, so a freshly mapped (zero-filled) region answers 0. The
     * pointer value itself is read by storing it into a one-element pointer array and
     * viewing that array's storage as an integer.
     */
    public static function addressOf(CData $pointer): int
    {
        $ffi       = self::ffi();
        $holder    = $ffi->new('void *[1]');
        $holder[0] = $ffi->cast('void *', $pointer);

        return (int) $ffi->cast('uint64_t[1]', $holder)[0];
    }

    /**
     * Initializes one PTHREAD_PROCESS_SHARED + PTHREAD_MUTEX_ROBUST mutex at $mutex
     */
    public static function initSharedMutex(CData $mutex): void
    {
        $ffi  = self::ffi();
        $attr = $ffi->new('char[' . self::ATTR_BUFFER_SIZE . ']');
        FFI::memset($attr, 0, self::ATTR_BUFFER_SIZE);

        $code = $ffi->pthread_mutexattr_init($attr);
        if ($code === 0) {
            $code = $ffi->pthread_mutexattr_setpshared($attr, self::PTHREAD_PROCESS_SHARED);
        }
        if ($code === 0) {
            $code = $ffi->pthread_mutexattr_setrobust($attr, self::PTHREAD_MUTEX_ROBUST);
        }
        if ($code === 0) {
            $code = $ffi->pthread_mutex_init($mutex, $attr);
        }
        $ffi->pthread_mutexattr_destroy($attr);

        if ($code !== 0) {
            throw ArenaException::mutexOperationFailed('pthread_mutex_init', -1, $code);
        }
    }

    /**
     * Measures sizeof(pthread_mutex_t) without a compiler, once per process
     *
     * There is no portable way to ask libc for the size of an opaque type from PHP, and a
     * hard-coded 40 (x86-64 glibc) would be a silent buffer overlap on a platform that
     * disagrees. So the size is measured: a buffer is painted with a filler byte, a mutex
     * is initialized into it, and the highest byte the initialization touched is the size.
     * glibc's pthread_mutex_init() clears the whole structure, which makes the answer
     * exact; a libc that writes only some fields yields a LOWER BOUND, which is why the
     * result is floored at the known x86-64/arm64 glibc size and the caller still verifies
     * it against the arena's slot stride.
     */
    public static function probeMutexSize(): int
    {
        if (self::$mutexSize !== null) {
            return self::$mutexSize;
        }

        $buffer = self::ffi()->new('char[' . self::PROBE_BUFFER_SIZE . ']');
        FFI::memset($buffer, self::PROBE_FILL, self::PROBE_BUFFER_SIZE);
        self::initSharedMutex($buffer);

        $touched = 0;
        for ($index = 0; $index < self::PROBE_BUFFER_SIZE; $index++) {
            if (self::ffi()->cast('unsigned char', $buffer[$index])->cdata !== self::PROBE_FILL) {
                $touched = $index + 1;
            }
        }
        self::ffi()->pthread_mutex_destroy($buffer);

        return self::$mutexSize = max($touched, Arena::MUTEX_SIZE_FLOOR);
    }

    /**
     * Takes one robust process-shared mutex, recovering a lock whose owner died
     *
     * `EOWNERDEAD` means the previous owner exited while holding the lock. The arena's own
     * critical sections are single aligned word updates, so the protected state cannot be
     * torn - declaring it consistent again and carrying on is exactly right here. Any
     * consumer that guards a multi-word structure with a stripe mutex has to make its own
     * consistency decision, which is why the recovery is reported through the return value.
     *
     * @return bool Whether the lock was recovered from a died owner
     */
    public static function lockMutex(CData $mutex, int $index): bool
    {
        $ffi  = self::ffi();
        $code = $ffi->pthread_mutex_lock($mutex);
        if ($code === 0) {
            return false;
        }
        if ($code === self::EOWNERDEAD) {
            $recovered = $ffi->pthread_mutex_consistent($mutex);
            if ($recovered !== 0) {
                throw ArenaException::mutexOperationFailed('pthread_mutex_consistent', $index, $recovered);
            }

            return true;
        }

        throw ArenaException::mutexOperationFailed('pthread_mutex_lock', $index, $code);
    }

    /**
     * @return bool Whether the lock was taken (false = held by somebody else right now)
     */
    public static function tryLockMutex(CData $mutex, int $index): bool
    {
        $ffi  = self::ffi();
        $code = $ffi->pthread_mutex_trylock($mutex);
        if ($code === self::EBUSY) {
            return false;
        }
        if ($code === self::EOWNERDEAD) {
            $recovered = $ffi->pthread_mutex_consistent($mutex);
            if ($recovered !== 0) {
                throw ArenaException::mutexOperationFailed('pthread_mutex_consistent', $index, $recovered);
            }

            return true;
        }
        if ($code !== 0) {
            throw ArenaException::mutexOperationFailed('pthread_mutex_trylock', $index, $code);
        }

        return true;
    }

    public static function unlockMutex(CData $mutex, int $index): void
    {
        $code = self::ffi()->pthread_mutex_unlock($mutex);
        if ($code !== 0) {
            throw ArenaException::mutexOperationFailed('pthread_mutex_unlock', $index, $code);
        }
    }
}
