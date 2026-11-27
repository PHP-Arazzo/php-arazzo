<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Policy;

use Alama\Arazzo\Runner\Exceptions\ExecutionError;

/**
 * Immutable execution step, workflow-nesting, and wall-clock deadline budget limits.
 */
final readonly class ExecutionBudgets
{
    public const int DEFAULT_MAX_STEPS = 1000;

    public const int DEFAULT_MAX_WORKFLOW_DEPTH = 32;

    public const float DEFAULT_EXECUTION_TIMEOUT = 300.0;

    public function __construct(
        public int $maxSteps = self::DEFAULT_MAX_STEPS,
        public int $maxWorkflowDepth = self::DEFAULT_MAX_WORKFLOW_DEPTH,
        public ?float $executionTimeout = self::DEFAULT_EXECUTION_TIMEOUT,
    ) {
        if ($this->executionTimeout !== null && $this->executionTimeout <= 0.0) {
            throw ExecutionError::invalidExecutionTimeout($this->executionTimeout);
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self(
            maxSteps: $maxSteps,
            maxWorkflowDepth: $this->maxWorkflowDepth,
            executionTimeout: $this->executionTimeout,
        );
    }

    public function withMaxWorkflowDepth(int $maxWorkflowDepth): self
    {
        return new self(
            maxSteps: $this->maxSteps,
            maxWorkflowDepth: $maxWorkflowDepth,
            executionTimeout: $this->executionTimeout,
        );
    }

    public function withExecutionTimeout(?float $seconds): self
    {
        return new self(
            maxSteps: $this->maxSteps,
            maxWorkflowDepth: $this->maxWorkflowDepth,
            executionTimeout: $seconds,
        );
    }
}
