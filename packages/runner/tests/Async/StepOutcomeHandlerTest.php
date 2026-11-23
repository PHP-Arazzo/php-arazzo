<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\Data\CriterionResult;
use Alama\Arazzo\Evaluation\Data\CriterionResultCollection;
use Alama\Arazzo\Evaluation\Enum\CriterionOutcome;
use Alama\Arazzo\Evaluation\Enum\CriterionReason;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\Async\SubWorkflowInvoker;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\GotoTargetNotFoundException;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Psr\Log\AbstractLogger;

class StepOutcomeMockStore implements ExecutionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $saves = [];

    /** @var list<array{executionId: string, status: ExecutionStatus}> */
    public array $completed = [];

    /** @var array<string, bool> */
    public array $outstanding = [];

    public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void
    {
        $this->saves[$executionId] = $state;
    }

    public function loadState(string $executionId): ?array
    {
        return $this->saves[$executionId] ?? null;
    }

    public function deleteState(string $executionId): void
    {
        unset($this->saves[$executionId]);
    }

    public function markStarted(string $executionId, string $definitionId, string $workflowId): void {}

    public function markCompleted(string $executionId, ExecutionStatus $status): void
    {
        $this->completed[] = ['executionId' => $executionId, 'status' => $status];
    }

    public function saveCorrelation(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
    {
        $this->outstanding[$executionId] = true;
    }

    public function findCorrelation(string $correlationId): ?PendingCorrelation
    {
        return null;
    }

    public function consumeCorrelation(string $correlationId): void {}

    public function hasPendingCorrelations(string $executionId): bool
    {
        return $this->outstanding[$executionId] ?? false;
    }
}

class StepOutcomeSpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

class StepOutcomeMockExpressionResolver implements EvaluationEngineInterface
{
    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed
    {
        return $expression->raw;
    }

    public function evaluateSuccessCriteria(Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): bool
    {
        return true;
    }

    public function evaluateCriteria(array $criteria, Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): CriterionResultCollection
    {
        if ($criteria === []) {
            return new CriterionResultCollection([]);
        }

        $matched = $criteria[0]->condition === 'MATCH';

        return new CriterionResultCollection([
            new CriterionResult(
                $criteria[0],
                $matched ? CriterionOutcome::Passed : CriterionOutcome::Failed,
                CriterionReason::Evaluated,
            ),
        ]);
    }

    /**
     * @return list<CriterionResult>
     */
    public function evaluateCriteriaDetailed(array $criteria, Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): array
    {
        return [];
    }

    public function evaluateSelector(Selector $selector, WorkflowContextInterface $context, string $stepId): mixed
    {
        return null;
    }

    public function interpolate(string $value, WorkflowContextInterface $context, string $stepId): string
    {
        return $value;
    }

    public function resolveValue(mixed $value, WorkflowContextInterface $context, ?string $stepId = null): mixed
    {
        if (is_string($value)) {
            return $this->interpolate($value, $context, $stepId ?? '');
        }

        if ($value instanceof Expression) {
            return $this->evaluate($value, new EvaluationContext($context, $stepId));
        }

        if ($value instanceof Selector) {
            return $this->evaluateSelector($value, $context, $stepId ?? '');
        }

        return $value;
    }

    public function replacePayload(Step $step, array $body, ?callable $resolveValue = null, ?WorkflowContext $context = null): array
    {
        return $body;
    }

    public function jsonPath(string $expression, array|object $data): mixed
    {
        return null;
    }

    public function jsonPointer(array $data, ?string $pointer): mixed
    {
        return null;
    }
}

/**
 * @param  list<FailureAction|Reusable>  $onFailure
 * @param  list<SuccessAction|Reusable>  $onSuccess
 * @param  array<int|string, mixed>  $dependsOn
 */
function stepOutcomeStep(string $id, array $onFailure = [], array $onSuccess = [], array $dependsOn = []): Step
{
    return new Step($id, null, new StepTarget(), new StepFlow(onSuccess: array_values($onSuccess), onFailure: array_values($onFailure), dependsOn: $dependsOn), new StepIo());
}

/**
 * @param  list<Step>  $steps
 * @param  list<FailureAction|Reusable>  $failureActions
 * @param  list<SuccessAction|Reusable>  $successActions
 */
function stepOutcomeWorkflow(string $id, array $steps, array $failureActions = [], array $successActions = []): Workflow
{
    return new Workflow($id, null, null, null, [], array_values($steps), array_values($successActions), array_values($failureActions), [], []);
}

/**
 * @param  list<Workflow>  $workflows
 */
function stepOutcomeDocument(array $workflows, ?Components $components = null): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [],
        workflows: array_values($workflows),
        components: $components ?? new Components([], [], [], []),
        specificationExtensions: [],
    );
}

/**
 * @return array{0: StepOutcomeHandler, 1: SyncQueueDriver, 2: StepOutcomeMockStore, 3: StepOutcomeSpyLogger}
 */
function makeStepOutcomeHandler(int $maxRetryAttempts = 10, bool $pendingCorrelationOutstanding = false, float $retryBackoffMultiplier = 1.0): array
{
    $queue = new SyncQueueDriver();
    $store = new StepOutcomeMockStore();
    $logger = new StepOutcomeSpyLogger();
    if ($pendingCorrelationOutstanding) {
        $store->outstanding['exec_1'] = true;
    }
    $resolver = new StepOutcomeMockExpressionResolver();

    $workflowEngine = new WorkflowEngine($resolver, $maxRetryAttempts, $retryBackoffMultiplier);
    $handler = new StepOutcomeHandler(
        $store,
        new RunControlFlow($workflowEngine, $queue, new ObservabilityContext(logger: $logger)),
        invoker: \Mockery::mock(SubWorkflowInvoker::class),
        evaluationEngine: \Mockery::mock(EvaluationEngineInterface::class),
    );

    return [$handler, $queue, $store, $logger];
}

it('continues normally and dispatches the next runnable step when criteria met and no actions match', function (): void {
    [$handler, $queue, $store] = makeStepOutcomeHandler();

    $stepA = stepOutcomeStep('A');
    $stepB = stepOutcomeStep('B', dependsOn: ['A']);
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB]);
    $document = stepOutcomeDocument([$workflow]);

    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $stepA, $context, 'exec_1', true);

    expect($queue->dispatched)->toHaveCount(1);
    expect($queue->dispatched[0]['job']->step->stepId)->toBe('B');
    expect($store->saves['exec_1']['steps']['A']['status'])->toBe(StepStatus::Succeeded);
});

it('terminates the execution as failed when criteria not met and no failure action matches', function (): void {
    [$handler, , $store] = makeStepOutcomeHandler();

    $step = stepOutcomeStep('A');
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($store->completed)->toHaveCount(1);
    expect($store->completed[0]['status'])->toBe(ExecutionStatus::Failed);
});

it('retries the same step with the configured delay and increments attempts', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $retry = new RetryAction('retry-1', retryAfter: 30, retryLimit: 3, stepId: null, workflowId: null, criteria: []);
    $step = stepOutcomeStep('A', onFailure: [$retry]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($queue->dispatched)->toHaveCount(1);
    expect($queue->dispatched[0]['delaySeconds'])->toBe(30);
    $dispatchedJob = $queue->dispatched[0]['job'];
    expect($dispatchedJob->step->stepId)->toBe('A');
    expect($dispatchedJob->context->getStepAttempts('A'))->toBe(1);
    expect($dispatchedJob->context->getStepStatus('A'))->toBe(StepStatus::Retrying);
});

it('falls through to the next onFailure action once the retry limit is exhausted', function (): void {
    [$handler, $queue, $store, $logger] = makeStepOutcomeHandler();

    $retry = new RetryAction('retry-1', retryAfter: 0, retryLimit: 1, stepId: null, workflowId: null, criteria: []);
    $step = stepOutcomeStep('A', onFailure: [$retry]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);

    $context = (new WorkflowContext('def_1'))
        ->withWorkflowId('wf_1')
        ->withExecutionId('exec_1')
        ->withStepAttemptIncremented('A');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($queue->dispatched)->toBeEmpty();
    expect($logger->records[0]['message'])->toContain('retry limit exhausted');
    expect($store->completed[0]['status'])->toBe(ExecutionStatus::Failed);
});

it('honors a config-level retry ceiling even when the document retryLimit is higher', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler(maxRetryAttempts: 2);

    $retry = new RetryAction('retry-1', retryAfter: 0, retryLimit: 100, stepId: null, workflowId: null, criteria: []);
    $step = stepOutcomeStep('A', onFailure: [$retry]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);

    $context = (new WorkflowContext('def_1'))
        ->withWorkflowId('wf_1')
        ->withExecutionId('exec_1')
        ->withStepAttemptIncremented('A')
        ->withStepAttemptIncremented('A');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($queue->dispatched)->toBeEmpty();
});

it('retries into a different target step, marking it Pending', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $retry = new RetryAction('retry-1', retryAfter: 5, retryLimit: 3, stepId: 'B', workflowId: null, criteria: []);
    $stepA = stepOutcomeStep('A', onFailure: [$retry]);
    $stepB = stepOutcomeStep('B');
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $stepA, $context, 'exec_1', false);

    $dispatchedJob = $queue->dispatched[0]['job'];
    expect($dispatchedJob->step->stepId)->toBe('B');
    expect($dispatchedJob->context->getStepStatus('B'))->toBe(StepStatus::Pending);
});

it('resolves a Reusable failure action from components before matching', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $retry = new RetryAction('shared-retry', retryAfter: 10, retryLimit: 3, stepId: null, workflowId: null, criteria: []);
    $components = new Components([], [], [], ['sharedRetry' => $retry]);
    $reusable = new Reusable('$components.failureActions.sharedRetry');

    $step = stepOutcomeStep('A', onFailure: [$reusable]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow], $components);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($queue->dispatched)->toHaveCount(1);
    expect($queue->dispatched[0]['delaySeconds'])->toBe(10);
});

it('only matches an action whose own criteria evaluate true, skipping ones that do not', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $skippedRetry = new RetryAction('no-match', retryAfter: 1, retryLimit: 3, stepId: null, workflowId: null, criteria: [
        new SuccessCriterion(null, 'NO_MATCH', null),
    ]);
    $matchedRetry = new RetryAction('matches', retryAfter: 2, retryLimit: 3, stepId: null, workflowId: null, criteria: [
        new SuccessCriterion(null, 'MATCH', null),
    ]);
    $step = stepOutcomeStep('A', onFailure: [$skippedRetry, $matchedRetry]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($queue->dispatched[0]['delaySeconds'])->toBe(2);
});

it('goto jumps to a same-workflow step directly, bypassing DependencyAnalyzer ordering', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-c', 'C', null, []);
    $stepA = stepOutcomeStep('A', onFailure: [$goto]);
    $stepB = stepOutcomeStep('B');
    $stepC = stepOutcomeStep('C', dependsOn: ['B']);
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB, $stepC]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $stepA, $context, 'exec_1', false);

    expect($queue->dispatched)->toHaveCount(1);
    $job = $queue->dispatched[0]['job'];
    expect($job->step->stepId)->toBe('C');
    expect($job->context->getStepStatus('C'))->toBe(StepStatus::Pending);
    expect($job->context->getStepStatus('A'))->toBe(StepStatus::Failed);
});

it('goto skips the intermediate step and dispatches no job for it after the target completes', function (): void {
    [$handler, $queue, $store] = makeStepOutcomeHandler();

    $goto = new SuccessGotoAction('goto', 'C', null, []);
    $stepA = stepOutcomeStep('A', onSuccess: [$goto]);
    $stepB = stepOutcomeStep('B');
    $stepC = stepOutcomeStep('C');
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB, $stepC]);
    $document = stepOutcomeDocument([$workflow]);

    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $stepA, $context, 'exec_1', true);

    expect($queue->dispatched)->toHaveCount(1);
    expect($queue->dispatched[0]['job']->step->stepId)->toBe('C');

    $cJobContext = $queue->dispatched[0]['job']->context;
    $handler->handle($document, $workflow, $stepC, $cJobContext, 'exec_1', true);

    expect($queue->dispatched)->toHaveCount(1, 'no job is dispatched for the skipped intermediate step B');
    expect($store->saves['exec_1']['steps']['B'] ?? null)->toBeNull();
});

it('goto loop-back resets an already-succeeded target step to Pending so it re-runs', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-a', 'A', null, []);
    $stepA = stepOutcomeStep('A');
    $stepB = stepOutcomeStep('B', onFailure: [$goto], dependsOn: ['A']);
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))
        ->withWorkflowId('wf_1')
        ->withExecutionId('exec_1')
        ->withStepResult('A', ['statusCode' => 200])
        ->withStepStatus('A', StepStatus::Succeeded);

    $handler->handle($document, $workflow, $stepB, $context, 'exec_1', false);

    $job = $queue->dispatched[0]['job'];
    expect($job->step->stepId)->toBe('A');
    expect($job->context->getStepStatus('A'))->toBe(StepStatus::Pending);
    expect($job->context->getStepStatus('B'))->toBe(StepStatus::Failed);
});

it('goto to a workflowId-only target hands off to Engine::evaluate for the target workflow entry steps', function (): void {
    [$handler, $queue] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-wf2', null, 'wf_2', []);
    $stepA = stepOutcomeStep('A', onFailure: [$goto]);
    $workflow1 = stepOutcomeWorkflow('wf_1', [$stepA]);
    $entryStep = stepOutcomeStep('entry');
    $workflow2 = stepOutcomeWorkflow('wf_2', [$entryStep]);
    $document = stepOutcomeDocument([$workflow1, $workflow2]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow1, $stepA, $context, 'exec_1', false);

    expect($queue->dispatched)->toHaveCount(1);
    $job = $queue->dispatched[0]['job'];
    expect($job->step->stepId)->toBe('entry');
    expect($job->context->getWorkflowId())->toBe('wf_2');
    expect($job->context->getStepStatus('A'))->toBe(StepStatus::Failed);
});

it('goto with both workflowId and stepId throws ExecutionError', function (): void {
    [$handler] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-wf2-mid', 'mid', 'wf_2', []);
    $stepA = stepOutcomeStep('A', onFailure: [$goto]);
    $workflow1 = stepOutcomeWorkflow('wf_1', [$stepA]);
    $entryStep = stepOutcomeStep('entry');
    $midStep = stepOutcomeStep('mid', dependsOn: ['entry']);
    $workflow2 = stepOutcomeWorkflow('wf_2', [$entryStep, $midStep]);
    $document = stepOutcomeDocument([$workflow1, $workflow2]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    expect(fn () => $handler->handle($document, $workflow1, $stepA, $context, 'exec_1', false))
        ->toThrow(ExecutionError::class);
});

it('goto to an unknown workflowId throws GotoTargetNotFoundException', function (): void {
    [$handler] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-missing', null, 'does-not-exist', []);
    $step = stepOutcomeStep('A', onFailure: [$goto]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    expect(fn () => $handler->handle($document, $workflow, $step, $context, 'exec_1', false))
        ->toThrow(GotoTargetNotFoundException::class);
});

it('goto to an unknown stepId in the current workflow throws GotoTargetNotFoundException', function (): void {
    [$handler] = makeStepOutcomeHandler();

    $goto = new FailureGotoAction('goto-missing-step', 'nope', null, []);
    $step = stepOutcomeStep('A', onFailure: [$goto]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    expect(fn () => $handler->handle($document, $workflow, $step, $context, 'exec_1', false))
        ->toThrow(GotoTargetNotFoundException::class);
});

it('SuccessEndAction terminates the execution as succeeded', function (): void {
    [$handler, $queue, $store] = makeStepOutcomeHandler();

    $end = new SuccessEndAction('end-ok', []);
    $step = stepOutcomeStep('A', onSuccess: [$end]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', true);

    expect($queue->dispatched)->toBeEmpty();
    expect($store->completed[0]['status'])->toBe(ExecutionStatus::Succeeded);
});

it('FailureEndAction terminates the execution as failed', function (): void {
    [$handler, , $store] = makeStepOutcomeHandler();

    $end = new FailureEndAction('end-fail', []);
    $step = stepOutcomeStep('A', onFailure: [$end]);
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false);

    expect($store->completed[0]['status'])->toBe(ExecutionStatus::Failed);
});

it('auto-completes the execution as succeeded once no steps are runnable and nothing is suspended', function (): void {
    [$handler, , $store] = makeStepOutcomeHandler();

    $step = stepOutcomeStep('A');
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', true);

    expect($store->completed)->toHaveCount(1);
    expect($store->completed[0]['status'])->toBe(ExecutionStatus::Succeeded);
});

it('does not auto-complete while a PendingCorrelation is still outstanding for the execution', function (): void {
    [$handler, , $store] = makeStepOutcomeHandler(pendingCorrelationOutstanding: true);

    $step = stepOutcomeStep('A');
    $workflow = stepOutcomeWorkflow('wf_1', [$step]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', true);

    expect($store->completed)->toBeEmpty();
});

it('does not auto-complete while downstream steps are still runnable', function (): void {
    [$handler, $queue, $store] = makeStepOutcomeHandler();

    $stepA = stepOutcomeStep('A');
    $stepB = stepOutcomeStep('B', dependsOn: ['A']);
    $workflow = stepOutcomeWorkflow('wf_1', [$stepA, $stepB]);
    $document = stepOutcomeDocument([$workflow]);
    $context = (new WorkflowContext('def_1'))->withWorkflowId('wf_1')->withExecutionId('exec_1');

    $handler->handle($document, $workflow, $stepA, $context, 'exec_1', true);

    expect($queue->dispatched)->toHaveCount(1);
    expect($store->completed)->toBeEmpty();
});
