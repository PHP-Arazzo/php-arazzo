<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Exceptions;

use InvalidArgumentException;

final class RegistryInitializationException extends InvalidArgumentException
{
    public static function missingExpressionEngine(string $registryName): self
    {
        return new self("ExpressionEngineInterface is required to initialize {$registryName}.");
    }
}
