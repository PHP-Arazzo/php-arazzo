<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use RuntimeException;

/**
 * @internal stays out of the advertised contract; used internally by the contracts package.
 */
final class SchemaValidationException extends RuntimeException
{
    /**
     * @param  list<array{path: string, message: string}>  $violations
     */
    public function __construct(
        public readonly string $stepId,
        public readonly array $violations,
        string $phase = 'Response',
    ) {
        $count = count($violations);
        $message = "{$phase} schema validation failed for step '{$stepId}' with {$count} violation(s).";
        if ($count > 0) {
            $first = $violations[0];
            $message .= " First violation at '{$first['path']}': {$first['message']}.";
        }

        parent::__construct($message);
    }

    /**
     * @param  list<array{path: string, message: string}>  $violations
     */
    public static function forStep(string $stepId, array $violations): self
    {
        return new self($stepId, $violations, 'Response');
    }

    /**
     * @param  list<array{path: string, message: string}>  $violations
     */
    public static function forRequestStep(string $stepId, array $violations): self
    {
        return new self($stepId, $violations, 'Request');
    }
}
