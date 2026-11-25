<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Throwable;

final class PreflightFailureException extends RunnerException
{
    public function __construct(
        string $message,
        public readonly ValidationResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, '', 'runner.preflight_failed', $previous);
    }

    public static function fromResult(ValidationResult $result): self
    {
        return new self(
            'Preflight validation failed with '.count($result->errors).' error(s).',
            $result,
        );
    }
}
