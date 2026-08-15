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
 * Ledger of every encoding call the package's own namespaces made
 *
 * The Never-Serialize Rule is easy to state and easy to break by accident, so the test suite
 * does not take the source code's word for it. PHP resolves an UNQUALIFIED function call
 * against the current namespace before it falls back to the global one, which means a
 * `serialize()` declared in `Lisachenko\SharedData\Ipc` intercepts every unqualified
 * `serialize()` made by this package's code in that namespace - without touching the global
 * function, PHPUnit or any dependency.
 *
 * serialization-guard.php declares those shadows for the three namespaces the data path runs
 * through and routes them here. A round trip that stays at zero calls has proven that no
 * value was encoded on the way; a round trip that increments anything names the culprit.
 */
final class SerializationGuard
{
    /** @var list<string> */
    private static array $calls = [];

    private function __construct()
    {
    }

    public static function record(string $function): void
    {
        self::$calls[] = $function;
    }

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @return list<string>
     */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function count(): int
    {
        return \count(self::$calls);
    }
}
