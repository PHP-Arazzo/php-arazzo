<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use RuntimeException;

final class PreflightFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ValidationResult $result,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
