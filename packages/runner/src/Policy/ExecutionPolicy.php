<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Policy;

use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Runtime\Policy\RetryPolicy;

/**
 * Immutable execution policy configuring workflow budgets, retry ceilings,
 * preflight behavior, durable state TTL, and per-source-description policies.
 */
final readonly class ExecutionPolicy
{
    public const int DEFAULT_MAX_STEPS = ExecutionBudgets::DEFAULT_MAX_STEPS;

    public const int DEFAULT_MAX_WORKFLOW_DEPTH = ExecutionBudgets::DEFAULT_MAX_WORKFLOW_DEPTH;

    public const float DEFAULT_EXECUTION_TIMEOUT = ExecutionBudgets::DEFAULT_EXECUTION_TIMEOUT;

    public const bool DEFAULT_PREFLIGHT = true;

    public const int DEFAULT_STATE_TTL_SECONDS = 86400;

    public const bool DEFAULT_NO_DEPS = false;

    /**
     * @param  array<string, SourcePolicy>  $sourcePolicies  Keyed by `sourceDescriptions[*].name`
     */
    public function __construct(
        public ExecutionBudgets $budgets = new ExecutionBudgets(),
        public ?RetryPolicy $retryPolicy = null,
        public bool $preflight = self::DEFAULT_PREFLIGHT,
        public int $stateTtlSeconds = self::DEFAULT_STATE_TTL_SECONDS,
        public array $sourcePolicies = [],
        public bool $noDeps = self::DEFAULT_NO_DEPS,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function maxSteps(): int
    {
        return $this->budgets->maxSteps;
    }

    public function maxWorkflowDepth(): int
    {
        return $this->budgets->maxWorkflowDepth;
    }

    public function executionTimeout(): ?float
    {
        return $this->budgets->executionTimeout;
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($this->budgets->withMaxSteps($maxSteps), $this->retryPolicy, $this->preflight, $this->stateTtlSeconds, $this->sourcePolicies, $this->noDeps);
    }

    public function withMaxWorkflowDepth(int $maxWorkflowDepth): self
    {
        return new self($this->budgets->withMaxWorkflowDepth($maxWorkflowDepth), $this->retryPolicy, $this->preflight, $this->stateTtlSeconds, $this->sourcePolicies, $this->noDeps);
    }

    public function withExecutionTimeout(?float $seconds): self
    {
        return new self(
            budgets: $this->budgets->withExecutionTimeout($seconds),
            retryPolicy: $this->retryPolicy,
            preflight: $this->preflight,
            stateTtlSeconds: $this->stateTtlSeconds,
            sourcePolicies: $this->sourcePolicies,
            noDeps: $this->noDeps,
        );
    }

    public function withRetryPolicy(RetryPolicy $retryPolicy): self
    {
        return new self($this->budgets, $retryPolicy, $this->preflight, $this->stateTtlSeconds, $this->sourcePolicies, $this->noDeps);
    }

    public function withPreflight(bool $enabled = true): self
    {
        return new self($this->budgets, $this->retryPolicy, $enabled, $this->stateTtlSeconds, $this->sourcePolicies, $this->noDeps);
    }

    public function withStateTtlSeconds(int $seconds): self
    {
        return new self($this->budgets, $this->retryPolicy, $this->preflight, $seconds, $this->sourcePolicies, $this->noDeps);
    }

    public function withNoDeps(bool $noDeps = true): self
    {
        return new self($this->budgets, $this->retryPolicy, $this->preflight, $this->stateTtlSeconds, $this->sourcePolicies, $noDeps);
    }

    public function withSourcePolicy(string $sourceName, SourcePolicy $policy): self
    {
        $sourcePolicies = $this->sourcePolicies;
        $sourcePolicies[$sourceName] = $policy;

        return new self($this->budgets, $this->retryPolicy, $this->preflight, $this->stateTtlSeconds, $sourcePolicies, $this->noDeps);
    }

    public function sourcePolicy(string $sourceName): ?SourcePolicy
    {
        return $this->sourcePolicies[$sourceName] ?? null;
    }
}
