<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Enum\StepState;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Engine\Data\StepTransition;
use Alama\Arazzo\Engine\Data\Transition;
use Alama\Arazzo\Engine\Execution\Data\InjectionResult;
use Alama\Arazzo\Runtime\State\Data\Budget;
use Alama\Arazzo\Runtime\State\Data\ErrorEntry;
use Alama\Arazzo\Runtime\State\Data\StepResult;
use GuzzleHttp\Psr7\Request;

test('engine Data objects host domain predicates directly (Tell, Dont Ask)', function (): void {
    $enter = StepTransition::enter(StepState::Pending, StepState::ExecutingRequest, 'start');
    $guard = StepTransition::guardFailed(StepState::Pending, 'budget');
    $cancelled = StepTransition::cancelled(StepState::ExecutingRequest, 'cancel', []);
    $timedOut = StepTransition::timedOut(StepState::ExecutingRequest, 'timeout', []);

    expect($enter->isGuardFailed())->toBeFalse()
        ->and($guard->isGuardFailed())->toBeTrue()
        ->and($guard->isCancelled())->toBeFalse()
        ->and($cancelled->isCancelled())->toBeTrue()
        ->and($timedOut->isTimedOut())->toBeTrue();

    $state = ExecutionState::start('e1', 'd1', 'w1');
    expect((new Transition($state, 'succeeded'))->isSucceeded())->toBeTrue()
        ->and((new Transition($state, 'succeeded'))->isFailed())->toBeFalse()
        ->and((new Transition($state, 'failed'))->isFailed())->toBeTrue();

    $req = new Request('GET', 'https://example.test');
    expect((new InjectionResult($req, 'idem-1', 'Idempotency-Key'))->wasInjected())->toBeTrue()
        ->and((new InjectionResult($req))->wasInjected())->toBeFalse();

    $budget = new Budget(maxSteps: 5, stepsSpent: 5, maxWorkflowDepth: 3, workflowCallStack: ['main']);
    expect($budget->remainingSteps())->toBe(0)
        ->and($budget->canEnterWorkflow())->toBeTrue()
        ->and($budget->isExhausted())->toBeTrue()
        ->and($budget->isOnCallStack('main'))->toBeTrue()
        ->and($budget->isOnCallStack('other'))->toBeFalse();

    $retryErr = new ErrorEntry('retry_exhausted', 's1', 3);
    $otherErr = new ErrorEntry('transport', 's1', 1);
    expect($retryErr->isRetryExhausted())->toBeTrue()
        ->and($otherErr->isRetryExhausted())->toBeFalse()
        ->and($retryErr->toArray()['type'])->toBe('retry_exhausted');

    $ok = StepResult::success(200, ['id' => 1], []);
    $fail = StepResult::failure(500, 'transport', [], []);
    expect($ok->isSucceeded())->toBeTrue()
        ->and($ok->isFailed())->toBeFalse()
        ->and($fail->isFailed())->toBeTrue()
        ->and($ok->toArray()['statusCode'])->toBe(200);
});
