<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Data\StepResult;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\GotoTargetNotFoundException;
use Alama\Arazzo\Runner\Exceptions\OperationResolutionException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\RunnerException;
use Alama\Arazzo\Runner\Exceptions\StepBudgetExceededException;
use Alama\Arazzo\Runner\Exceptions\UnsupportedStepException;
use Alama\Arazzo\Runner\Exceptions\WorkflowCycleException;
use Alama\Arazzo\Runner\Exceptions\WorkflowDepthExceededException;
use Alama\Arazzo\Runner\Execution\Data\SubWorkflowResult;
use Alama\Arazzo\Runner\Execution\Data\Transition;

test('runner domain exception factories construct typed exceptions', function (): void {
    $doc = new ArazzoDocument('1.0.1', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $result = new ValidationResult($doc, [new Error('e', 'bad', '/')], []);

    expect(GotoTargetNotFoundException::unknownStep('s1', 'wf1'))->toBeInstanceOf(RunnerException::class)
        ->and(GotoTargetNotFoundException::unknownWorkflow('go', 'wf2')->getMessage())->toContain('wf2')
        ->and(GotoTargetNotFoundException::wrongReusableTarget('$components.x.y', 'successActions')->getMessage())->toContain('successActions')
        ->and(GotoTargetNotFoundException::unresolvableReusable('$components.successActions.nope')->getMessage())->toContain('does not resolve')
        ->and(WorkflowCycleException::detected('wf1')->getMessage())->toContain('wf1')
        ->and(WorkflowDepthExceededException::exceeded(8)->getMessage())->toContain('8')
        ->and(StepBudgetExceededException::exceeded('exec-1', 10)->getMessage())->toContain('10')
        ->and(PreflightFailureException::fromResult($result)->result)->toBe($result)
        ->and(ExecutionError::stateMissing('exec-1')->getMessage())->toContain('exec-1')
        ->and(ExecutionError::ambiguousActionTarget('act')->getMessage())->toContain('act')
        ->and(ExecutionError::stepFailed('s1')->getMessage())->toContain('s1')
        ->and(ExecutionError::workflowFailedAtStep('wf1', 's1')->getMessage())->toContain('wf1')
        ->and(ExecutionError::workflowEndedInFailure('wf1', 's1')->getMessage())->toContain('s1')
        ->and(ExecutionError::dependencyFailed('dep')->getMessage())->toContain('dep')
        ->and(OperationResolutionException::unsupportedStep('s1'))->toBeInstanceOf(RunnerException::class)
        ->and(UnsupportedStepException::noSupportingExecutor('s1'))->toBeInstanceOf(LogicException::class)
        ->and(UnsupportedStepException::unknownWorkflow('wf1')->getMessage())->toContain('wf1');
});

test('runner Data objects host domain queries directly (Tell, Dont Ask)', function (): void {
    $state = ExecutionState::start('exec-1', 'def-1', 'wf-main');
    $wf = new Workflow('wf-main', null, null, null, [], [], [], [], [], []);
    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'getPet');
    $ctx = new WorkflowContext('def-1', [], [], [], 'wf-main', 'exec-1');

    $okEnd = Transition::end($state, 'succeeded');
    $failEnd = Transition::end($state, 'failed');
    $gotoOther = Transition::goto($state, 's2', 'wf-other');
    $nextLocal = Transition::next($state, 's2');

    expect($okEnd->isSucceeded())->toBeTrue()
        ->and($okEnd->isFailed())->toBeFalse()
        ->and($failEnd->isFailed())->toBeTrue()
        ->and($gotoOther->targetWorkflowId($wf))->toBe('wf-other')
        ->and($nextLocal->targetWorkflowId($wf))->toBe('wf-main')
        ->and((new StepResult('s1', true))->isFailed())->toBeFalse()
        ->and((new StepResult('s1', false))->isFailed())->toBeTrue()
        ->and((new ExecuteStepJob($step, $ctx))->executionId())->toBe('exec-1')
        ->and((new ExecuteStepJob($step, $ctx))->stepId())->toBe('s1')
        ->and((new SubWorkflowResult([], 'succeeded', 'child-1'))->isSucceeded())->toBeTrue()
        ->and((new SubWorkflowResult([], 'failed', 'child-2'))->isSucceeded())->toBeFalse();
});
