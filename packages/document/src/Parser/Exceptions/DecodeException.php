<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use RuntimeException;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class DecodeException extends RuntimeException
{
    public static function fromThrowable(Throwable $previous): self
    {
        return new self($previous->getMessage(), 0, $previous);
    }
}
