<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

use InvalidArgumentException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class TypeCastException extends InvalidArgumentException
{
    public static function toInteger(): self
    {
        return new self('Cannot cast to integer.');
    }

    public static function toFloat(): self
    {
        return new self('Cannot cast to float.');
    }

    public static function toBoolean(): self
    {
        return new self('Cannot cast to boolean.');
    }

    public static function toString(): self
    {
        return new self('Cannot cast to string.');
    }
}
