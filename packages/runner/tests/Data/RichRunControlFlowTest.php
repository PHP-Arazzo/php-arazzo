<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;

test('run control flow hosts with* mutators (Tell, Dont Ask)', function (): void {
    $engine = new WorkflowEngine($this->createMock(EvaluationEngineInterface::class));
    $queue = $this->createMock(QueueDriverInterface::class);
    $observability = new ObservabilityContext();
    $policy = new ExecutionPolicy();

    $controlFlow = new RunControlFlow(
        workflowEngine: $engine,
        queueDriver: $queue,
        observability: $observability,
        policy: $policy,
    );

    expect($controlFlow->workflowEngine)->toBe($engine);
    expect($controlFlow->queueDriver)->toBe($queue);
    expect($controlFlow->observability)->toBe($observability);
    expect($controlFlow->policy)->toBe($policy);
    expect($controlFlow->preflight)->toBeNull();

    // withWorkflowEngine returns a new instance with the engine replaced
    $otherEngine = new WorkflowEngine($this->createMock(EvaluationEngineInterface::class));
    $withEngine = $controlFlow->withWorkflowEngine($otherEngine);
    expect($withEngine)->not->toBe($controlFlow);
    expect($withEngine->workflowEngine)->toBe($otherEngine);
    expect($withEngine->queueDriver)->toBe($queue);
    expect($withEngine->policy)->toBe($policy);

    // withQueueDriver returns a new instance with the queue driver replaced
    $otherQueue = $this->createMock(QueueDriverInterface::class);
    $withQueue = $controlFlow->withQueueDriver($otherQueue);
    expect($withQueue)->not->toBe($controlFlow);
    expect($withQueue->queueDriver)->toBe($otherQueue);
    expect($withQueue->workflowEngine)->toBe($engine);

    // withObservability returns a new instance with observability replaced
    $otherObservability = new ObservabilityContext();
    $withObservability = $controlFlow->withObservability($otherObservability);
    expect($withObservability)->not->toBe($controlFlow);
    expect($withObservability->observability)->toBe($otherObservability);
    expect($withObservability->policy)->toBe($policy);

    // withPolicy returns a new instance with the policy replaced
    $otherPolicy = new ExecutionPolicy();
    $withPolicy = $controlFlow->withPolicy($otherPolicy);
    expect($withPolicy)->not->toBe($controlFlow);
    expect($withPolicy->policy)->toBe($otherPolicy);
    expect($withPolicy->queueDriver)->toBe($queue);

    // withPreflight returns a new instance with preflight set/replaced
    $preflight = new Preflight(new OperationResolvers([]));
    $withPreflight = $controlFlow->withPreflight($preflight);
    expect($withPreflight)->not->toBe($controlFlow);
    expect($withPreflight->preflight)->toBe($preflight);

    // withPreflight(null) clears preflight
    $cleared = $withPreflight->withPreflight(null);
    expect($cleared->preflight)->toBeNull();
    expect($cleared->workflowEngine)->toBe($engine);
});
