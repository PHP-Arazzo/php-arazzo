<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\Data\StepResult;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\Data\Transition;
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

test('step result hosts domain queries (Tell, Dont Ask)', function (): void {
    $okResult = new StepResult('step_1', true, ['token' => 'abc']);
    $failResult = new StepResult('step_2', false, [], new RuntimeException('Connection failed'));

    expect($okResult->isSucceeded())->toBeTrue()
        ->and($okResult->isFailed())->toBeFalse()
        ->and($okResult->hasError())->toBeFalse()
        ->and($okResult->errorMessage())->toBeNull()
        ->and($okResult->output('token'))->toBe('abc')
        ->and($okResult->output('missing'))->toBeNull()
        ->and($okResult->hasOutput('token'))->toBeTrue()
        ->and($okResult->hasOutput('missing'))->toBeFalse()
        ->and($okResult->toArray())->toBe([
            'stepId' => 'step_1',
            'success' => true,
            'outputs' => ['token' => 'abc'],
            'error' => null,
        ]);

    expect($failResult->isSucceeded())->toBeFalse()
        ->and($failResult->isFailed())->toBeTrue()
        ->and($failResult->hasError())->toBeTrue()
        ->and($failResult->errorMessage())->toBe('Connection failed')
        ->and($failResult->toArray()['error'])->toBe('Connection failed');
});

test('execution result hosts domain queries (Tell, Dont Ask)', function (): void {
    $step1 = new StepResult('s1', true, ['userId' => 42]);
    $step2 = new StepResult('s2', false, [], new RuntimeException('Step 2 exploded'));

    $exec = new ExecutionResult(
        workflowId: 'wf_main',
        status: ExecutionStatus::Failed,
        outputs: ['final' => 'done'],
        stepResults: ['s1' => $step1, 's2' => $step2],
        stepsSpent: 2,
        workflowCallStack: ['wf_main'],
    );

    expect($exec->isSucceeded())->toBeFalse()
        ->and($exec->isFailed())->toBeTrue()
        ->and($exec->stepResult('s1'))->toBe($step1)
        ->and($exec->stepResult('missing'))->toBeNull()
        ->and($exec->hasStepResult('s1'))->toBeTrue()
        ->and($exec->hasStepResult('missing'))->toBeFalse()
        ->and($exec->output('final'))->toBe('done')
        ->and($exec->output('nope'))->toBeNull()
        ->and($exec->hasOutput('final'))->toBeTrue()
        ->and($exec->hasOutput('nope'))->toBeFalse()
        ->and($exec->failedStep())->toBe($step2)
        ->and($exec->executedStepIds())->toBe(['s1', 's2']);

    $okExec = new ExecutionResult('wf_ok', ExecutionStatus::Succeeded, ['x' => 1], ['s1' => $step1]);
    expect($okExec->isSucceeded())->toBeTrue()
        ->and($okExec->failedStep())->toBeNull();
});

test('transition hosts domain predicates (Tell, Dont Ask)', function (): void {
    $state = new ExecutionState('exec_1', 'def_1', 'wf_1');

    $next = Transition::next($state, 's2');
    expect($next->isNext())->toBeTrue()
        ->and($next->isRetry())->toBeFalse()
        ->and($next->isGoto())->toBeFalse()
        ->and($next->isInvoke())->toBeFalse()
        ->and($next->isSuspend())->toBeFalse()
        ->and($next->isTerminal())->toBeFalse();

    $retry = Transition::retry($state, 's1', 5);
    expect($retry->isRetry())->toBeTrue()
        ->and($retry->isNext())->toBeFalse();

    $goto = Transition::goto($state, 's3', 'wf_2');
    expect($goto->isGoto())->toBeTrue()
        ->and($goto->isRetry())->toBeFalse();

    $invoke = Transition::invoke($state, 'wf_child');
    expect($invoke->isInvoke())->toBeTrue()
        ->and($invoke->isGoto())->toBeFalse();

    $suspend = Transition::suspend($state);
    expect($suspend->isSuspend())->toBeTrue()
        ->and($suspend->isInvoke())->toBeFalse();

    $endSucceeded = Transition::end($state, 'succeeded');
    expect($endSucceeded->isTerminal())->toBeTrue()
        ->and($endSucceeded->isSucceeded())->toBeTrue()
        ->and($endSucceeded->isFailed())->toBeFalse();

    $endFailed = Transition::end($state, 'failed');
    expect($endFailed->isTerminal())->toBeTrue()
        ->and($endFailed->isFailed())->toBeTrue()
        ->and($endFailed->isSucceeded())->toBeFalse();
});
