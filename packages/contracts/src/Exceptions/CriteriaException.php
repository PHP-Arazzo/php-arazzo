<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown or recorded when step or workflow success criteria evaluate to false.
 */
final class CriteriaException extends RuntimeException implements ArazzoExecutionExceptionInterface
{
    public function __construct(
        string $message,
        public readonly string $stepId,
        public readonly string $condition = '',
        ?Throwable $previous = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function unmet(string $stepId, string $condition, string $details = '', ?Throwable $previous = null): self
    {
        $message = "Success criteria unmet for step '{$stepId}' on condition '{$condition}'";
        if ($details !== '') {
            $message .= ": {$details}";
        }

        return new self($message, $stepId, $condition, $previous);
    }
}
