<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Runner\Sync;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\ExecutionTimeoutException;
use Alama\Arazzo\Runner\Policy\ExecutionBudgets;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Tests\Support\RecordingEventDispatcher;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

function advancingClock(): ClockInterface
{
    return new class() implements ClockInterface
    {
        private DateTimeImmutable $now;

        public function __construct()
        {
            $this->now = new DateTimeImmutable('2026-11-27 12:00:00.000000');
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }

        public function advanceSeconds(int $seconds): void
        {
            $this->now = $this->now->modify("+{$seconds} seconds");
        }
    };
}

/**
 * @param  list<string>  $executed
 */
function slowStepPlugin(ClockInterface $clock, int $secondsPerStep, array &$executed, bool $failSteps = false): OperationExecutorPluginInterface
{
    return new class($clock, $secondsPerStep, $executed, $failSteps) implements OperationExecutorPluginInterface
    {
        /** @param list<string> $executed */
        public function __construct(
            private readonly ClockInterface $clock,
            private readonly int $secondsPerStep,
            public array &$executed,
            private readonly bool $failSteps,
        ) {}

        public function name(): string
        {
            return 'slow-http';
        }

        public function priority(): int
        {
            return 10;
        }

        public function supports(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
        {
            $this->executed[] = $step->stepId;
            if (method_exists($this->clock, 'advanceSeconds')) {
                $this->clock->advanceSeconds($this->secondsPerStep);
            }

            if ($this->failSteps) {
                return StepExecutionOutcome::resolved(503, [], ['error' => 'retry']);
            }

            return StepExecutionOutcome::resolved(200, ['v' => 'ok'], ['ok' => true]);
        }
    };
}

/**
 * @param  list<Workflow>  $workflows
 */
function timeoutDocument(string $title, array $workflows): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info(title: $title, summary: null, description: null, version: '1'),
        sourceDescriptions: [],
        workflows: $workflows,
        components: new Components(inputs: [], parameters: [], successActions: [], failureActions: []),
        specificationExtensions: [],
    );
}

/**
 * @param  list<Step>  $steps
 * @param  list<string>  $dependsOn
 */
function timeoutWorkflow(string $id, array $steps, array $dependsOn = []): Workflow
{
    return new Workflow(
        workflowId: $id,
        summary: null,
        description: null,
        inputs: null,
        dependsOn: $dependsOn,
        steps: $steps,
        successActions: [],
        failureActions: [],
        outputs: [],
        parameters: [],
    );
}

it('configures executionTimeout on ExecutionBudgets and ExecutionPolicy with 300s default', function (): void {
    $budgets = ExecutionBudgets::default();
    expect($budgets->executionTimeout)->toBe(300.0)
        ->and($budgets->withExecutionTimeout(45.5)->executionTimeout)->toBe(45.5)
        ->and($budgets->withExecutionTimeout(null)->executionTimeout)->toBeNull();

    $policy = ExecutionPolicy::default();
    expect($policy->executionTimeout())->toBe(300.0)
        ->and($policy->withExecutionTimeout(12.0)->executionTimeout())->toBe(12.0)
        ->and($policy->withExecutionTimeout(null)->executionTimeout())->toBeNull();
});

it('aborts workflow execution when cumulative elapsed time exceeds executionTimeout (S6)', function (): void {
    $clock = advancingClock();
    $executed = [];
    $plugin = slowStepPlugin($clock, 200, $executed);

    $doc = timeoutDocument('Timeout Test', [
        timeoutWorkflow('wf-timeout', [
            StepFactory::http('step-1', null, new StepFlow(), new StepIo(), operationId: 'op1'),
            StepFactory::http('step-2', null, new StepFlow(), new StepIo(), operationId: 'op2'),
            StepFactory::http('step-3', null, new StepFlow(), new StepIo(), operationId: 'op3'),
        ]),
    ]);

    $events = new RecordingEventDispatcher();
    $runner = Runner::create()
        ->withClock($clock)
        ->withEventDispatcher($events)
        ->withExecutor($plugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(300.0));

    try {
        $runner->run($doc, 'wf-timeout');
        test()->fail('Expected ExecutionTimeoutException was not thrown');
    } catch (ExecutionTimeoutException $e) {
        expect($e->getErrorCode())->toBe('transport.execution_timeout')
            ->and($executed)->toBe(['step-1', 'step-2']);
    }

    $runFailed = array_values(array_filter(
        $events->events,
        static fn (object $event): bool => $event instanceof RunFailedEvent,
    ));

    expect($runFailed)->toHaveCount(1)
        ->and($runFailed[0]->category)->toBe('transport')
        ->and($runFailed[0]->cause)->toBeInstanceOf(ExecutionTimeoutException::class);
});

it('aborts even when the single or final step exceeds executionTimeout without emitting RunCompletedEvent', function (): void {
    $clock = advancingClock();
    $executed = [];
    $plugin = slowStepPlugin($clock, 350, $executed);

    $doc = timeoutDocument('Single Step Timeout', [
        timeoutWorkflow('wf-single', [
            StepFactory::http('only-step', null, new StepFlow(), new StepIo(), operationId: 'op1'),
        ]),
    ]);

    $events = new RecordingEventDispatcher();
    expect(fn () => Runner::create()
        ->withClock($clock)
        ->withEventDispatcher($events)
        ->withExecutor($plugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(300.0))
        ->run($doc, 'wf-single'))
        ->toThrow(ExecutionTimeoutException::class);

    $completed = array_filter($events->events, static fn (object $e): bool => $e instanceof RunCompletedEvent);
    $failed = array_filter($events->events, static fn (object $e): bool => $e instanceof RunFailedEvent);

    expect($completed)->toHaveCount(0)
        ->and($failed)->toHaveCount(1);
});

it('aborts retry loops and dependsOn chains when executionTimeout is exceeded', function (): void {
    // Retry loop timeout
    $retryClock = advancingClock();
    $retryExecuted = [];
    $retryPlugin = slowStepPlugin($retryClock, 120, $retryExecuted, failSteps: true);
    $retryAction = new RetryAction(name: 'retrySlow', retryAfter: 0.0, retryLimit: 5, workflowId: null, stepId: null, criteria: []);
    $retryDoc = timeoutDocument('Retry Timeout', [
        timeoutWorkflow('wf-retry', [
            StepFactory::http('s1', null, new StepFlow(onFailure: [$retryAction]), new StepIo(), operationId: 'op1'),
        ]),
    ]);

    expect(fn () => Runner::create()
        ->withClock($retryClock)
        ->withExecutor($retryPlugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(200.0))
        ->run($retryDoc, 'wf-retry'))
        ->toThrow(ExecutionTimeoutException::class)
        ->and($retryExecuted)->toBe(['s1', 's1']);

    // dependsOn chain timeout: dependency exceeds deadline, main workflow never starts
    $depClock = advancingClock();
    $depExecuted = [];
    $depPlugin = slowStepPlugin($depClock, 150, $depExecuted);
    $depDoc = timeoutDocument('DependsOn Timeout', [
        timeoutWorkflow('wf-dep', [
            StepFactory::http('dep-step', null, new StepFlow(), new StepIo(), operationId: 'op1'),
        ]),
        timeoutWorkflow('wf-main', [
            StepFactory::http('main-step', null, new StepFlow(), new StepIo(), operationId: 'op2'),
        ], dependsOn: ['wf-dep']),
    ]);

    $depEvents = new RecordingEventDispatcher();
    expect(fn () => Runner::create()
        ->withClock($depClock)
        ->withEventDispatcher($depEvents)
        ->withExecutor($depPlugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(100.0))
        ->run($depDoc, 'wf-main'))
        ->toThrow(ExecutionTimeoutException::class)
        ->and($depExecuted)->toBe(['dep-step']);

    $depCompleted = array_filter($depEvents->events, static fn (object $e): bool => $e instanceof RunCompletedEvent);
    expect($depCompleted)->toHaveCount(0);
});

it('allows unbounded execution when executionTimeout is null', function (): void {
    $clock = advancingClock();
    $executed = [];
    $plugin = slowStepPlugin($clock, 500, $executed);

    $doc = timeoutDocument('Unbounded Test', [
        timeoutWorkflow('wf-unbounded', [
            StepFactory::http('step-1', null, new StepFlow(), new StepIo(), operationId: 'op1'),
            StepFactory::http('step-2', null, new StepFlow(), new StepIo(), operationId: 'op2'),
        ]),
    ]);

    $result = Runner::create()
        ->withClock($clock)
        ->withExecutor($plugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(null))
        ->run($doc, 'wf-unbounded');

    expect($result->isSucceeded())->toBeTrue()
        ->and($result->executedStepIds())->toBe(['step-1', 'step-2']);
});

it('rejects non-positive executionTimeout and enforces timeout during runStep', function (): void {
    expect(fn () => ExecutionBudgets::default()->withExecutionTimeout(0.0))
        ->toThrow(ExecutionError::class);

    $clock = advancingClock();
    $executed = [];
    $plugin = slowStepPlugin($clock, 60, $executed);

    $doc = timeoutDocument('Step Timeout', [
        timeoutWorkflow('wf-step-timeout', [
            StepFactory::http('dep', null, new StepFlow(), new StepIo(), operationId: 'op1'),
            StepFactory::http('target', null, new StepFlow(dependsOn: ['dep']), new StepIo(), operationId: 'op2'),
        ]),
    ]);

    expect(fn () => Runner::create()
        ->withClock($clock)
        ->withExecutor($plugin)
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false)->withExecutionTimeout(30.0))
        ->runStep($doc, 'target', workflowId: 'wf-step-timeout'))
        ->toThrow(ExecutionTimeoutException::class);
});
