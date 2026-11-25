<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Policy;

/**
 * Immutable execution step and workflow-nesting budget limits.
 */
final readonly class ExecutionBudgets
{
    public const int DEFAULT_MAX_STEPS = 1000;

    public const int DEFAULT_MAX_WORKFLOW_DEPTH = 32;

    public function __construct(
        public int $maxSteps = self::DEFAULT_MAX_STEPS,
        public int $maxWorkflowDepth = self::DEFAULT_MAX_WORKFLOW_DEPTH,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($maxSteps, $this->maxWorkflowDepth);
    }

    public function withMaxWorkflowDepth(int $maxWorkflowDepth): self
    {
        return new self($this->maxSteps, $maxWorkflowDepth);
    }
}
