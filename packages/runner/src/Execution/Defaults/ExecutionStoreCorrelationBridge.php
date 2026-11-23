<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Defaults;

use Alama\Arazzo\Contracts\Interfaces\PendingCorrelationRegistryInterface;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Runner\ExecutionStoreInterface;

/**
 * Bridges {@see ExecutionStoreInterface} onto {@see PendingCorrelationRegistryInterface}
 * for protocol executors (such as AsyncApiStepExecutor) that still consume the
 * narrow correlation contract.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class ExecutionStoreCorrelationBridge implements PendingCorrelationRegistryInterface
{
    public function __construct(
        private ExecutionStoreInterface $store,
    ) {}

    public function create(
        string $correlationId,
        string $executionId,
        string $stepId,
        string $channelPath,
        ?int $timeoutSeconds = null,
    ): void {
        $this->store->saveCorrelation($correlationId, $executionId, $stepId, $channelPath, $timeoutSeconds);
    }

    public function findByCorrelationId(string $correlationId): ?PendingCorrelation
    {
        return $this->store->findCorrelation($correlationId);
    }

    public function consume(string $correlationId): void
    {
        $this->store->consumeCorrelation($correlationId);
    }

    public function existsForExecution(string $executionId): bool
    {
        return $this->store->hasPendingCorrelations($executionId);
    }
}
