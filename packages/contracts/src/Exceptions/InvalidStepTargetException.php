<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use InvalidArgumentException;

final class InvalidStepTargetException extends InvalidArgumentException
{
    public static function httpRequiresSingleOperation(): self
    {
        return new self('http target requires exactly one of operationId or operationPath');
    }
}
