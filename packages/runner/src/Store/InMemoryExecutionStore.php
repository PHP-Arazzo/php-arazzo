<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Store;

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Default in-memory {@see ExecutionStoreInterface} for local runs and tests.
 */
final class InMemoryExecutionStore implements ExecutionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $states = [];

    /** @var array<string, ExecutionStatus> */
    private array $statuses = [];

    /** @var array<string, PendingCorrelation> */
    private array $correlations = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void
    {
        unset($ttlSeconds);
        $this->states[$executionId] = $state;
    }

    public function loadState(string $executionId): ?array
    {
        return $this->states[$executionId] ?? null;
    }

    public function deleteState(string $executionId): void
    {
        unset($this->states[$executionId]);
    }

    public function markStarted(string $executionId, string $definitionId, string $workflowId): void
    {
        unset($definitionId, $workflowId);
        $this->statuses[$executionId] = ExecutionStatus::Running;
    }

    public function markCompleted(string $executionId, ExecutionStatus $status): void
    {
        $this->statuses[$executionId] = $status;
    }

    public function saveCorrelation(
        string $correlationId,
        string $executionId,
        string $stepId,
        string $channelPath,
        ?int $timeoutSeconds = null,
    ): void {
        $expiresAt = $timeoutSeconds !== null
            ? $this->clock->now()->modify("+{$timeoutSeconds} seconds")
            : null;

        $this->correlations[$correlationId] = new PendingCorrelation(
            correlationId: $correlationId,
            executionId: $executionId,
            stepId: $stepId,
            channelPath: $channelPath,
            expiresAt: $expiresAt,
        );
    }

    public function findCorrelation(string $correlationId): ?PendingCorrelation
    {
        return $this->correlations[$correlationId] ?? null;
    }

    public function consumeCorrelation(string $correlationId): void
    {
        unset($this->correlations[$correlationId]);
    }

    public function hasPendingCorrelations(string $executionId): bool
    {
        foreach ($this->correlations as $correlation) {
            if ($correlation->executionId === $executionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inspect the latest recorded lifecycle status for an execution.
     */
    public function statusOf(string $executionId): ?ExecutionStatus
    {
        return $this->statuses[$executionId] ?? null;
    }
}
