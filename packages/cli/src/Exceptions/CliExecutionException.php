<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class CliExecutionException extends CliException
{
    public static function maxQueuedStepsExceeded(string $executionId, int $maxSteps): self
    {
        return new self(
            "CLI run '{$executionId}' exceeded {$maxSteps} queued steps; possible dispatch loop.",
            '',
            'cli.max_queued_steps_exceeded',
        );
    }
}
