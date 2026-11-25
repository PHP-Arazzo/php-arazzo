<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Console\Cli;

use Alama\Arazzo\Cli\Console\Cli\CliExecutionStore;
use Alama\Arazzo\Cli\Console\Cli\CliRunResult;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runtime\State\InMemoryStateStore;

it('exposes the raw constructor values', function (): void {
    $result = new CliRunResult('exec_1', 'succeeded', false);

    expect($result->executionId)->toBe('exec_1')
        ->and($result->status)->toBe('succeeded')
        ->and($result->suspended)->toBeFalse();
});

it('reports success and failure from the status string', function (): void {
    expect((new CliRunResult('e', 'succeeded', false))->succeeded())->toBeTrue()
        ->and((new CliRunResult('e', 'succeeded', false))->failed())->toBeFalse()
        ->and((new CliRunResult('e', 'failed', false))->failed())->toBeTrue()
        ->and((new CliRunResult('e', 'failed', false))->succeeded())->toBeFalse();
});

it('reads the final status from a CLI execution store when succeeded', function (): void {
    $store = new CliExecutionStore(new InMemoryStateStore());
    $store->markCompleted('exec_1', ExecutionStatus::Succeeded);

    $result = CliRunResult::fromStore('exec_1', $store);

    expect($result->status)->toBe('succeeded')
        ->and($result->suspended)->toBeFalse()
        ->and($result->succeeded())->toBeTrue();
});

it('reads a failed status from an in-memory execution store', function (): void {
    $store = new InMemoryExecutionStore();
    $store->markCompleted('exec_1', ExecutionStatus::Failed);

    $result = CliRunResult::fromStore('exec_1', $store);

    expect($result->status)->toBe('failed')
        ->and($result->suspended)->toBeFalse()
        ->and($result->failed())->toBeTrue();
});

it('treats an in-process run still running as suspended', function (): void {
    $store = new CliExecutionStore(new InMemoryStateStore());
    $store->markStarted('exec_1', 'def_1', 'wf');

    $result = CliRunResult::fromStore('exec_1', $store);

    expect($result->status)->toBe('running')
        ->and($result->suspended)->toBeTrue();
});

it('falls back to running when the store has no record', function (): void {
    $store = new CliExecutionStore(new InMemoryStateStore());

    $result = CliRunResult::fromStore('exec_missing', $store);

    expect($result->status)->toBe('running')
        ->and($result->suspended)->toBeTrue();
});

it('reports running and suspended for a custom execution store', function (): void {
    $store = new class() implements ExecutionStoreInterface
    {
        public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void {}

        public function loadState(string $executionId): ?array
        {
            return null;
        }

        public function deleteState(string $executionId): void {}

        public function markStarted(string $executionId, string $definitionId, string $workflowId): void {}

        public function markCompleted(string $executionId, ExecutionStatus $status): void {}

        public function saveCorrelation(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void {}

        public function findCorrelation(string $correlationId): ?PendingCorrelation
        {
            return null;
        }

        public function consumeCorrelation(string $correlationId): void {}

        public function hasPendingCorrelations(string $executionId): bool
        {
            return false;
        }
    };

    $result = CliRunResult::fromStore('exec_1', $store);

    expect($result->status)->toBe('running')
        ->and($result->suspended)->toBeTrue()
        ->and($result->succeeded())->toBeFalse();
});
