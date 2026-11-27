<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Runner\Data\Enum\StepRole;
use Alama\Arazzo\Runner\Data\StepExecutionResult;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;

test('StepExecutionResult carries StepRole and predicate helpers', function (): void {
    $standard = new StepExecutionResult('s1', true, ['token' => 'abc']);
    expect($standard->role)->toBe(StepRole::Standard)
        ->and($standard->isTarget())->toBeFalse()
        ->and($standard->isDependency())->toBeFalse()
        ->and($standard->toArray()['role'])->toBe('standard')
        ->and($standard->toArray()['dependency'])->toBeFalse()
        ->and($standard->toArray()['target'])->toBeFalse();

    $dep = new StepExecutionResult('s1', true, ['token' => 'abc'], role: StepRole::Dependency);
    expect($dep->role)->toBe(StepRole::Dependency)
        ->and($dep->isDependency())->toBeTrue()
        ->and($dep->isTarget())->toBeFalse()
        ->and($dep->toArray()['dependency'])->toBeTrue();

    $target = new StepExecutionResult('s2', true, ['orderId' => 42], role: StepRole::Target);
    expect($target->role)->toBe(StepRole::Target)
        ->and($target->isTarget())->toBeTrue()
        ->and($target->isDependency())->toBeFalse()
        ->and($target->toArray()['target'])->toBeTrue();
});

test('WorkflowExecutionResult distinguishes target from dependency step results', function (): void {
    $dep1 = new StepExecutionResult('login', true, ['token' => 'x'], role: StepRole::Dependency);
    $dep2 = new StepExecutionResult('fetch-config', true, ['cfg' => 'y'], role: StepRole::Dependency);
    $target = new StepExecutionResult('create-order', true, ['orderId' => 99], role: StepRole::Target);

    $result = new WorkflowExecutionResult(
        workflowId: 'order-wf',
        status: ExecutionStatus::Succeeded,
        outputs: ['finalOrder' => 99],
        stepResults: [
            'login' => $dep1,
            'fetch-config' => $dep2,
            'create-order' => $target,
        ],
        stepsSpent: 3,
        sequentialFallbacks: ['--step implies sequential execution'],
    );

    expect($result->targetStepResult())->toBe($target)
        ->and($result->dependencyStepResults())->toBe([
            'login' => $dep1,
            'fetch-config' => $dep2,
        ])
        ->and($result->hasSequentialFallbacks())->toBeTrue()
        ->and($result->sequentialFallbacks)->toBe(['--step implies sequential execution'])
        ->and($result->toArray()['sequentialFallbacks'])->toBe(['--step implies sequential execution']);
});
