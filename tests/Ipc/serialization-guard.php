<?php

declare(strict_types=1);

/**
 * Shared data PHP extension
 *
 * @copyright Copyright 2021, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Namespace-local shadows of every encoding function, for the three namespaces this
 * package's data path runs through. An unqualified call from package code lands here,
 * is recorded in SerializationGuard and then forwarded to the real global function -
 * so the guard observes without changing behaviour. Loaded explicitly by the test that
 * asserts on it (bracketed namespaces cannot be autoloaded).
 */

namespace Lisachenko\SharedData\Ipc {
    function serialize(mixed $value): string
    {
        SerializationGuard::record(__FUNCTION__);

        return \serialize($value);
    }

    function unserialize(string $data, array $options = []): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \unserialize($data, $options);
    }

    function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        SerializationGuard::record(__FUNCTION__);

        return \json_encode($value, $flags, $depth);
    }

    function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \json_decode($json, $associative, $depth, $flags);
    }

    function igbinary_serialize(mixed $value): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \function_exists('\igbinary_serialize') ? \igbinary_serialize($value) : null;
    }

    function igbinary_unserialize(string $data): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \function_exists('\igbinary_unserialize') ? \igbinary_unserialize($data) : null;
    }

    function var_export(mixed $value, bool $return = false): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \var_export($value, $return);
    }
}

namespace Lisachenko\SharedData {
    use Lisachenko\SharedData\Ipc\SerializationGuard;

    function serialize(mixed $value): string
    {
        SerializationGuard::record(__FUNCTION__);

        return \serialize($value);
    }

    function unserialize(string $data, array $options = []): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \unserialize($data, $options);
    }

    function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        SerializationGuard::record(__FUNCTION__);

        return \json_encode($value, $flags, $depth);
    }

    function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \json_decode($json, $associative, $depth, $flags);
    }

    function igbinary_serialize(mixed $value): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \function_exists('\igbinary_serialize') ? \igbinary_serialize($value) : null;
    }

    function var_export(mixed $value, bool $return = false): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \var_export($value, $return);
    }
}

namespace Lisachenko\SharedData\Shm {
    use Lisachenko\SharedData\Ipc\SerializationGuard;

    function serialize(mixed $value): string
    {
        SerializationGuard::record(__FUNCTION__);

        return \serialize($value);
    }

    function unserialize(string $data, array $options = []): mixed
    {
        SerializationGuard::record(__FUNCTION__);

        return \unserialize($data, $options);
    }

    function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        SerializationGuard::record(__FUNCTION__);

        return \json_encode($value, $flags, $depth);
    }

    function igbinary_serialize(mixed $value): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \function_exists('\igbinary_serialize') ? \igbinary_serialize($value) : null;
    }

    function var_export(mixed $value, bool $return = false): ?string
    {
        SerializationGuard::record(__FUNCTION__);

        return \var_export($value, $return);
    }
}
