<?php

declare(strict_types=1);

use Alama\Arazzo\Cli\Console\Cli\CliExecutionStore;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Runtime\State\InMemoryStateStore;
use Psr\Clock\ClockInterface;

it('persists, loads, and deletes hot state and tracks lifecycle status and pending correlations', function (): void {
    $fixedNow = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $clock = new class($fixedNow) implements ClockInterface
    {
        public function __construct(private readonly DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };

    $store = new CliExecutionStore(new InMemoryStateStore(), $clock);

    $store->saveState('exec_1', ['workflowId' => 'wf_1'], 60);
    expect($store->loadState('exec_1'))->toBe(['workflowId' => 'wf_1']);
    $store->deleteState('exec_1');
    expect($store->loadState('exec_1'))->toBeNull();

    $store->markStarted('exec_1', 'def_1', 'wf_1');
    expect($store->statusOf('exec_1'))->toBe(ExecutionStatus::Running);
    $store->markCompleted('exec_1', ExecutionStatus::Succeeded);
    expect($store->statusOf('exec_1'))->toBe(ExecutionStatus::Succeeded);

    expect($store->hasPendingCorrelations('exec_1'))->toBeFalse();
    $store->saveCorrelation('corr_1', 'exec_1', 'wait', 'events/orders', 120);
    $found = $store->findCorrelation('corr_1');

    expect($found)->not->toBeNull()
        ->and($found->executionId)->toBe('exec_1')
        ->and($found->expiresAt)->toEqual($fixedNow->modify('+120 seconds'))
        ->and($store->hasPendingCorrelations('exec_1'))->toBeTrue()
        ->and($store->hasPendingCorrelations('exec_other'))->toBeFalse();

    $store->consumeCorrelation('corr_1');
    expect($store->findCorrelation('corr_1'))->toBeNull()
        ->and($store->hasPendingCorrelations('exec_1'))->toBeFalse();

    $store->saveCorrelation('corr_no_ttl', 'exec_1', 'wait', 'events/orders');
    expect($store->findCorrelation('corr_no_ttl')?->expiresAt)->toBeNull();
});
