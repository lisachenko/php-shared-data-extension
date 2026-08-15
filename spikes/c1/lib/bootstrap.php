<?php

declare(strict_types=1);

/**
 * Spike harness bootstrap.
 *
 * Wires up, WITHOUT touching the agent-owned repos:
 *   - a hand-rolled PSR-4 autoloader for ZEngine\  (8.4 line from /home/user/z-engine,
 *     8.5 line from the scratch clone of z-engine's `master` branch) and for
 *     Lisachenko\SharedData\ (from /home/user/php-shared-data-extension).
 *   - a libc FFI binding with mmap/munmap/pthread pshared mutex primitives.
 *   - fork / pipe helpers used by every spike.
 *
 * z-engine is OPTIONAL here: the pure-FFI half of every spike must run even when the
 * engine bridge cannot boot (e.g. no z-engine line for the running PHP minor).
 */

const SPIKE_ROOT = __DIR__ . '/..';

// ---------------------------------------------------------------------------
// z-engine / shared-data autoloading
// ---------------------------------------------------------------------------

function spike_zengine_dir(): ?string
{
    $candidates = PHP_VERSION_ID >= 80500
        ? [SPIKE_ROOT . '/zengine-85']          // master == 8.5.x-dev
        : ['/home/user/z-engine'];              // 8.4 branch == 8.4.x-dev

    foreach ($candidates as $dir) {
        if (is_dir($dir . '/src') && is_dir($dir . '/include/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)) {
            return $dir;
        }
    }

    return null;
}

spl_autoload_register(static function (string $class): void {
    static $prefixes = null;
    if ($prefixes === null) {
        $prefixes = [];
        $ze       = spike_zengine_dir();
        if ($ze !== null) {
            $prefixes['ZEngine\\'] = $ze . '/src/';
        }
        $prefixes['Lisachenko\\SharedData\\'] = '/home/user/php-shared-data-extension/src/';
    }

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** Boots z-engine's Core, returns null on success or the failure reason. */
function spike_boot_zengine(): ?string
{
    $dir = spike_zengine_dir();
    if ($dir === null) {
        return sprintf('no z-engine line available for PHP %s in this sandbox', PHP_VERSION);
    }
    try {
        \ZEngine\Core::init();
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return null;
}

// ---------------------------------------------------------------------------
// libc binding: mmap + robust pshared mutexes
// ---------------------------------------------------------------------------

const PROT_READ      = 1;
const PROT_WRITE     = 2;
const MAP_SHARED     = 0x01;
const MAP_PRIVATE    = 0x02;
const MAP_ANONYMOUS  = 0x20;   // Linux x86-64

// glibc / Linux x86-64 constants
const PTHREAD_PROCESS_SHARED = 1;
const PTHREAD_MUTEX_ROBUST   = 1;
const EOWNERDEAD             = 130;
const ENOTRECOVERABLE        = 131;
const EBUSY                  = 16;

function libc(): FFI
{
    static $ffi = null;
    if ($ffi !== null) {
        return $ffi;
    }

    // glibc x86-64: pthread_mutex_t is 40 bytes, pthread_mutexattr_t is 4.
    // We over-size the opaque blobs to 64/8 bytes so a slot is cache-line sized.
    $ffi = FFI::cdef(<<<'C'
        typedef struct { char __opaque[64]; } spike_mutex_t;
        typedef struct { char __opaque[8]; }  spike_mutexattr_t;

        // NOTE: mmap is declared returning char* on purpose. FFI::cast('uintptr_t', $p)
        // on a `void *` CData yields 0 in PHP 8.4/8.5 (void* is special-cased and the
        // cast reinterprets the *pointee*); on any typed pointer it yields the address.
        char *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
        int   munmap(char *addr, size_t length);
        int   mprotect(char *addr, size_t len, int prot);

        int pthread_mutexattr_init(spike_mutexattr_t *attr);
        int pthread_mutexattr_setpshared(spike_mutexattr_t *attr, int pshared);
        int pthread_mutexattr_setrobust(spike_mutexattr_t *attr, int robust);
        int pthread_mutexattr_settype(spike_mutexattr_t *attr, int type);
        int pthread_mutex_init(spike_mutex_t *mutex, const spike_mutexattr_t *attr);
        int pthread_mutex_lock(spike_mutex_t *mutex);
        int pthread_mutex_trylock(spike_mutex_t *mutex);
        int pthread_mutex_unlock(spike_mutex_t *mutex);
        int pthread_mutex_consistent(spike_mutex_t *mutex);
        int pthread_mutex_destroy(spike_mutex_t *mutex);

        char *memcpy(char *dest, const char *src, size_t n);
        char *memset(char *s, int c, size_t n);
        int   memcmp(const char *a, const char *b, size_t n);
        int   getpid(void);
        void  _exit(int status);
        unsigned int sleep(unsigned int seconds);
        C, null);

    return $ffi;
}

/**
 * Anonymous shared mapping. Returns [void* cdata, size].
 */
/** @return int base address of a fresh zero-filled MAP_SHARED|MAP_ANONYMOUS region */
function spike_mmap_shared(int $size): int
{
    return spike_mmap($size, MAP_SHARED | MAP_ANONYMOUS);
}

/** @return int base address of a fresh zero-filled MAP_PRIVATE|MAP_ANONYMOUS region */
function spike_mmap_private(int $size): int
{
    return spike_mmap($size, MAP_PRIVATE | MAP_ANONYMOUS);
}

function spike_mmap(int $size, int $flags): int
{
    $ptr  = libc()->mmap(null, $size, PROT_READ | PROT_WRITE, $flags, -1, 0);
    $addr = spike_addr($ptr);
    if ($addr === 0 || $addr === -1) {
        throw new RuntimeException(sprintf('mmap(size=%d, flags=0x%x) failed', $size, $flags));
    }
    libc()->memset($ptr, 0, $size);

    return $addr;
}

function spike_addr(object $p): int
{
    return (int) FFI::cast('uintptr_t', $p)->cdata;
}

/** Materializes a typed pointer at a raw address (allocation-free view, via libc binding). */
function spike_at(string $type, int $address): FFI\CData
{
    return libc()->cast($type . '*', $address);
}

/**
 * Initializes a process-shared (optionally robust) mutex at $address inside a shared mapping.
 */
function spike_mutex_init(int $address, bool $robust = false): FFI\CData
{
    $ffi   = libc();
    $attr  = $ffi->new('spike_mutexattr_t');
    $rc    = $ffi->pthread_mutexattr_init(FFI::addr($attr));
    $rc   |= $ffi->pthread_mutexattr_setpshared(FFI::addr($attr), PTHREAD_PROCESS_SHARED);
    if ($robust) {
        $rc |= $ffi->pthread_mutexattr_setrobust(FFI::addr($attr), PTHREAD_MUTEX_ROBUST);
    }
    if ($rc !== 0) {
        throw new RuntimeException('pthread_mutexattr_* failed');
    }

    $mutex = $ffi->cast('spike_mutex_t*', $address);
    $rc    = $ffi->pthread_mutex_init($mutex, FFI::addr($attr));
    if ($rc !== 0) {
        throw new RuntimeException("pthread_mutex_init failed: {$rc}");
    }

    return $mutex;
}

function spike_mutex_at(int $address): FFI\CData
{
    return libc()->cast('spike_mutex_t*', $address);
}

function spike_lock(FFI\CData $m): int
{
    return libc()->pthread_mutex_lock($m);
}

function spike_unlock(FFI\CData $m): int
{
    return libc()->pthread_mutex_unlock($m);
}

/**
 * Typed engine pointer at a raw address, through z-engine's FFI binding
 * (needed for zval / zend_object / zend_array / zend_string views).
 */
function spike_engine_at(string $type, int $address): object
{
    return \ZEngine\Core::pointerAtAddress($type, $address);
}

// ---------------------------------------------------------------------------
// process helpers
// ---------------------------------------------------------------------------

/** @return array{0:resource,1:resource} */
function spike_pipe(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('stream_socket_pair failed');
    }

    return $pair;
}

/**
 * Forks $n children, runs $body($index) in each, exits the child with the returned code.
 *
 * @return list<int> child pids
 */
function spike_fork(int $n, callable $body): array
{
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            $code = 0;
            try {
                $code = (int) $body($i);
            } catch (\Throwable $e) {
                fwrite(STDERR, "child {$i} threw: " . get_class($e) . ': ' . $e->getMessage() . "\n");
                $code = 66;
            }
            // Hard exit: skip PHP shutdown so engine teardown never touches shared memory
            spike_hard_exit($code);
        }
        $pids[] = $pid;
    }

    return $pids;
}

/**
 * Leaves the child WITHOUT running PHP's shutdown sequence.
 *
 * Object/GC teardown in a forked child would walk (and free) memory that the parent and
 * the siblings still own, so every spike child leaves through this door.
 */
function spike_hard_exit(int $code): never
{
    try {
        libc()->_exit($code);
    } catch (\Throwable) {
        // fall through
    }
    exit($code);
}

/** @return array<int,int> pid => exit status description */
function spike_wait(array $pids): array
{
    $result = [];
    foreach ($pids as $pid) {
        $status = 0;
        pcntl_waitpid($pid, $status);
        if (pcntl_wifexited($status)) {
            $result[$pid] = ['exit' => pcntl_wexitstatus($status), 'signal' => null];
        } elseif (pcntl_wifsignaled($status)) {
            $result[$pid] = ['exit' => null, 'signal' => pcntl_wtermsig($status)];
        } else {
            $result[$pid] = ['exit' => null, 'signal' => null];
        }
    }

    return $result;
}

function spike_describe_wait(array $waits): string
{
    $parts = [];
    foreach ($waits as $pid => $w) {
        $parts[] = $w['signal'] !== null
            ? sprintf('pid %d killed by signal %d (%s)', $pid, $w['signal'], spike_signame($w['signal']))
            : sprintf('pid %d exit %s', $pid, var_export($w['exit'], true));
    }

    return implode(', ', $parts);
}

function spike_signame(int $sig): string
{
    $map = [4 => 'SIGILL', 6 => 'SIGABRT', 7 => 'SIGBUS', 8 => 'SIGFPE', 9 => 'SIGKILL', 11 => 'SIGSEGV'];

    return $map[$sig] ?? "sig{$sig}";
}

// ---------------------------------------------------------------------------
// reporting
// ---------------------------------------------------------------------------

function spike_header(string $id, string $title): void
{
    printf("=== %s — %s ===\n", $id, $title);
    printf("PHP %s (%s), ZTS=%s, pid=%d\n", PHP_VERSION, PHP_OS, ZEND_THREAD_SAFE ? 'yes' : 'no', getmypid());
    $err = spike_boot_zengine();
    printf("z-engine: %s\n\n", $err === null ? 'booted (' . spike_zengine_dir() . ')' : 'UNAVAILABLE — ' . $err);
}

function spike_step(string $text): void
{
    printf("--- %s\n", $text);
}

function spike_result(string $label, bool $ok, string $detail = ''): void
{
    printf("[%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail === '' ? '' : ' :: ' . $detail);
}

function spike_note(string $text): void
{
    printf("      %s\n", $text);
}
