<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\BackoffCalculatorInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runtime\Policy\ExponentialBackoffCalculator;
use Alama\Arazzo\Runtime\Policy\RetryPolicy;
use Psr\Clock\ClockInterface;

function retryPolicyStep(): Step
{
    return new Step('step', null, new StepTarget(), new StepFlow(), new StepIo());
}

function retryPolicyContext(array $headers = []): WorkflowContext
{
    return new WorkflowContext('def', [], [
        'step' => ['response' => ['statusCode' => 500, 'headers' => $headers, 'body' => []]],
    ]);
}

it('computes exponential backoff per attempt', function (): void {
    $calculator = new ExponentialBackoffCalculator();

    expect($calculator->calculate(10, 1, 2.0))->toBe(10)
        ->and($calculator->calculate(10, 2, 2.0))->toBe(20)
        ->and($calculator->calculate(10, 3, 2.0))->toBe(40)
        ->and($calculator->calculate(10, 4, 1.0))->toBe(10);
});

it('returns zero delay for non-positive base', function (): void {
    expect((new ExponentialBackoffCalculator())->calculate(0, 3, 2.0))->toBe(0)
        ->and((new ExponentialBackoffCalculator())->calculate(-5, 3, 2.0))->toBe(0);
});

it('honors a numeric Retry-After header over the declared delay', function (): void {
    $policy = new RetryPolicy(maxAttempts: 5, backoffMultiplier: 3.0);
    $action = new RetryAction(name: 'retry', retryAfter: 99.0, retryLimit: null, stepId: null, workflowId: null, criteria: []);

    expect($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(['Retry-After' => '42']), 1))->toBe(42);
});

it('scales the declared delay by the multiplier per upcoming attempt', function (): void {
    $policy = new RetryPolicy(maxAttempts: 5, backoffMultiplier: 2.0);
    $action = new RetryAction(name: 'retry', retryAfter: 10.0, retryLimit: null, stepId: null, workflowId: null, criteria: []);

    expect($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(), 1))->toBe(10)
        ->and($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(), 2))->toBe(20)
        ->and($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(), 3))->toBe(40);
});

it('is exhausted at the tighter of action and policy limits', function (): void {
    $policy = new RetryPolicy(maxAttempts: 3);

    expect($policy->isExhausted(2, 10))->toBeFalse()
        ->and($policy->isExhausted(3, 10))->toBeTrue()
        ->and($policy->isExhausted(1, 1))->toBeTrue()
        ->and($policy->isExhausted(3, null))->toBeTrue();
});

it('constructs via ::default() and produces immutable copies via fluent with* methods', function (): void {
    $customCalc = new class() implements BackoffCalculatorInterface
    {
        public function calculate(int|float $baseDelay, int $attempt, float $multiplier): int
        {
            return 77;
        }
    };
    $fixedClock = new class() implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-01-01T00:00:00Z');
        }
    };

    $base = RetryPolicy::default();
    $custom = $base
        ->withMaxAttempts(4)
        ->withBackoffMultiplier(2.5)
        ->withCalculator($customCalc)
        ->withClock($fixedClock);

    expect($base->maxAttempts)->toBe(RetryPolicy::DEFAULT_MAX_ATTEMPTS)
        ->and($base->backoffMultiplier)->toBe(RetryPolicy::DEFAULT_BACKOFF_MULTIPLIER)
        ->and($custom->maxAttempts)->toBe(4)
        ->and($custom->backoffMultiplier)->toBe(2.5)
        ->and($custom->calculator)->toBe($customCalc)
        ->and($custom->clock)->toBe($fixedClock);

    $action = new RetryAction(name: 'retry', retryAfter: 5.0, retryLimit: null, stepId: null, workflowId: null, criteria: []);
    expect($custom->calculateDelay($action, retryPolicyStep(), new WorkflowContext('def'), 1))->toBe(77);
});

it('uses the injected PSR-20 clock for RFC 7231 Retry-After date headers and clamps past dates to zero', function (): void {
    $fixedClock = new class() implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-06-15T12:00:00Z');
        }
    };

    $policy = RetryPolicy::default()->withClock($fixedClock);
    $action = new RetryAction(name: 'retry', retryAfter: 9.0, retryLimit: null, stepId: null, workflowId: null, criteria: []);

    // 45 seconds in the future relative to the injected clock
    $futureHeader = (new DateTimeImmutable('2026-06-15T12:00:45Z'))->format(\DATE_RFC7231);
    expect($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(['retry-after' => $futureHeader]), 1))
        ->toBe(45);

    // Date in the past clamps to 0
    $pastHeader = (new DateTimeImmutable('2026-06-15T11:59:00Z'))->format(\DATE_RFC7231);
    expect($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(['Retry-After' => $pastHeader]), 1))
        ->toBe(0);

    // Unparseable Retry-After falls back to action delay
    expect($policy->calculateDelay($action, retryPolicyStep(), retryPolicyContext(['Retry-After' => 'not-a-date']), 1))
        ->toBe(9);

    // Non-array headers in step response are safely ignored
    $malformedContext = new WorkflowContext('def', [], [
        'step' => ['response' => ['statusCode' => 500, 'headers' => 'invalid', 'body' => []]],
    ]);
    expect($policy->calculateDelay($action, retryPolicyStep(), $malformedContext, 1))->toBe(9);
});

it('rejects invalid maxAttempts or backoffMultiplier', function (): void {
    expect(fn () => RetryPolicy::default()->withMaxAttempts(0))
        ->toThrow(InvalidArgumentException::class, 'maxAttempts');
    expect(fn () => RetryPolicy::default()->withBackoffMultiplier(0.0))
        ->toThrow(InvalidArgumentException::class, 'backoffMultiplier');
});
