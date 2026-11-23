<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;

/**
 * Single coarse persistence port for durable {@see AsyncRunner} executions.
 *
 * Owns in-flight hot execution state, run lifecycle status, and pending
 * correlations for suspended receive/interaction steps. Append-only audit
 * history (`ExecutionHistory`) and verified snapshots (`ExecutionSnapshot`)
 * are layered by platform hosts rather than required for OSS execution.
 */
interface ExecutionStoreInterface
{
    /**
     * Persist serialized in-flight execution state.
     *
     * @param  array<string, mixed>  $state
     */
    public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void;

    /**
     * Load serialized in-flight execution state, or null if absent/expired.
     *
     * @return array<string, mixed>|null
     */
    public function loadState(string $executionId): ?array;

    /**
     * Remove serialized in-flight execution state.
     */
    public function deleteState(string $executionId): void;

    /**
     * Record that an execution has started (or resumed running).
     */
    public function markStarted(string $executionId, string $definitionId, string $workflowId): void;

    /**
     * Record that an execution reached a terminal status.
     */
    public function markCompleted(string $executionId, ExecutionStatus $status): void;

    /**
     * Register a pending correlation for a suspended step awaiting an external message.
     */
    public function saveCorrelation(
        string $correlationId,
        string $executionId,
        string $stepId,
        string $channelPath,
        ?int $timeoutSeconds = null,
    ): void;

    /**
     * Look up an active pending correlation by id.
     */
    public function findCorrelation(string $correlationId): ?PendingCorrelation;

    /**
     * Consume (remove) a pending correlation once handled or expired.
     */
    public function consumeCorrelation(string $correlationId): void;

    /**
     * Report whether the execution still has any unconsumed pending correlations.
     */
    public function hasPendingCorrelations(string $executionId): bool;
}
