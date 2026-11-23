<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\Data\CriterionResult;
use Alama\Arazzo\Evaluation\Data\CriterionResultCollection;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;
use Alama\Arazzo\Runner\Async\CorrelationResumer;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\Async\SubWorkflowInvoker;
use Alama\Arazzo\Runner\Events\CorrelationPendingEvent;
use Alama\Arazzo\Runner\Events\CorrelationResumedEvent;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;

class HandoffMockEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}

class HandoffMockStore implements ExecutionStoreInterface
{
    public ?PendingCorrelation $toReturn = null;

    /** @var array<string, array<string, mixed>> */
    public array $saves = [];

    /** @var array<string, mixed>|null */
    public ?array $preloaded = null;

    /** @var list<array{correlationId: string, executionId: string, stepId: string, channelPath: string, timeoutSeconds: ?int}> */
    public array $created = [];

    /** @var list<string> */
    public array $consumed = [];

    public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void
    {
        $this->saves[$executionId] = $state;
    }

    public function loadState(string $executionId): ?array
    {
        return $this->preloaded ?? $this->saves[$executionId] ?? null;
    }

    public function deleteState(string $executionId): void
    {
        unset($this->saves[$executionId]);
        $this->preloaded = null;
    }

    public function markStarted(string $executionId, string $definitionId, string $workflowId): void {}

    public function markCompleted(string $executionId, ExecutionStatus $status): void {}

    public function saveCorrelation(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
    {
        $this->created[] = [
            'correlationId' => $correlationId,
            'executionId' => $executionId,
            'stepId' => $stepId,
            'channelPath' => $channelPath,
            'timeoutSeconds' => $timeoutSeconds,
        ];
    }

    public function findCorrelation(string $correlationId): ?PendingCorrelation
    {
        return $this->toReturn;
    }

    public function consumeCorrelation(string $correlationId): void
    {
        $this->consumed[] = $correlationId;
    }

    public function hasPendingCorrelations(string $executionId): bool
    {
        return false;
    }
}

class HandoffMockExpressionResolver implements EvaluationEngineInterface
{
    public ?Closure $correlationHook = null;

    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed
    {
        if ($this->correlationHook !== null) {
            return ($this->correlationHook)($expression->raw);
        }

        return $expression->raw;
    }

    public function evaluateSuccessCriteria(Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): bool
    {
        return true;
    }

    public function evaluateCriteria(array $criteria, Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): CriterionResultCollection
    {
        return new CriterionResultCollection([]);
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

class HandoffMockQueueDriver implements QueueDriverInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $job, int $delaySeconds = 0): void
    {
        $this->dispatched[] = $job;
    }
}

class HandoffMockLockManager implements LockManagerInterface
{
    public function acquire(string $key, int $ttlSeconds, callable $callback): mixed
    {
        return $callback();
    }

    public function tryAcquire(string $key, int $ttlSeconds): bool
    {
        return true;
    }

    public function release(string $key): void {}
}

/**
 * @param  list<Workflow>  $workflows
 */
function handoffActorDocument(array $workflows): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [],
        workflows: array_values($workflows),
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

function handoffActorContext(Step $step, string $executionId = 'exec_1'): WorkflowContext
{
    return new WorkflowContext(
        definitionId: 'test-def',
        steps: [$step->stepId => ['status' => StepStatus::Pending->value]],
        workflowId: 'wf_1',
        executionId: $executionId,
    );
}

/**
 * @return array{0: StepOutcomeHandler, 1: HandoffMockStore, 2: HandoffMockEventDispatcher, 3: HandoffMockExpressionResolver, 4: HandoffMockQueueDriver}
 */
function makeHandoffOutcomeHandler(WorkflowEngine $engine): array
{
    $queue = new HandoffMockQueueDriver();
    $store = new HandoffMockStore();
    $resolver = new HandoffMockExpressionResolver();
    $events = new HandoffMockEventDispatcher();

    $handler = new StepOutcomeHandler(
        $store,
        new RunControlFlow($engine, $queue, new ObservabilityContext(events: $events)),
        invoker: \Mockery::mock(SubWorkflowInvoker::class),
        evaluationEngine: $resolver,
    );

    return [$handler, $store, $events, $resolver, $queue];
}

/** @return list<CorrelationPendingEvent> */
function handoffPendingEvents(HandoffMockEventDispatcher $events): array
{
    return array_values(array_filter(
        $events->dispatched,
        static fn (object $event): bool => $event instanceof CorrelationPendingEvent,
    ));
}

it('registers the pending record and emits the event for a suspended interaction step', function (): void {
    $step = new Step('ask-human', null, StepTarget::interaction(new Interaction(prompt: 'Approve?')), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step], [], [], [], []);
    $document = handoffActorDocument([$workflow]);
    $context = handoffActorContext($step);

    [$handler, $store, $events] = makeHandoffOutcomeHandler(new WorkflowEngine(new HandoffMockExpressionResolver()));

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false, suspended: true);

    expect($store->created)->toHaveCount(1)
        ->and($store->created[0])->toMatchArray([
            'correlationId' => '',
            'executionId' => 'exec_1',
            'stepId' => 'ask-human',
            'channelPath' => '',
        ]);

    $pendingEvents = handoffPendingEvents($events);
    expect($pendingEvents)->toHaveCount(1)
        ->and($pendingEvents[0]->stepId)->toBe('ask-human')
        ->and($pendingEvents[0]->correlationId)->toBe('')
        ->and($pendingEvents[0]->channelPath)->toBe('');
});

it('registers the evaluated correlation id for a suspended receive step', function (): void {
    $step = new Step('wait-approval', null, StepTarget::async('receive', 'channels/approvals', new Expression('$request.body#/id')), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step], [], [], [], []);
    $document = handoffActorDocument([$workflow]);
    $context = handoffActorContext($step);

    [$handler, $store, $events] = makeHandoffOutcomeHandler(new WorkflowEngine(new HandoffMockExpressionResolver()));

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false, suspended: true);

    expect($store->created)->toHaveCount(1)
        ->and($store->created[0]['correlationId'])->toBe('$request.body#/id')
        ->and($store->created[0]['channelPath'])->toBe('channels/approvals');

    $pendingEvents = handoffPendingEvents($events);
    expect($pendingEvents)->toHaveCount(1)
        ->and($pendingEvents[0]->correlationId)->toBe('$request.body#/id');
});

it('produces an empty correlation id rather than a cast error for a non-scalar correlation', function (): void {
    $step = new Step('wait-approval', null, StepTarget::async('receive', 'channels/approvals', new Expression('$request.body#/id')), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step], [], [], [], []);
    $document = handoffActorDocument([$workflow]);
    $context = handoffActorContext($step);

    [$handler, $store, $events, $resolver] = makeHandoffOutcomeHandler(new WorkflowEngine(new HandoffMockExpressionResolver()));
    $resolver->correlationHook = static fn (string $raw): mixed => new \stdClass();

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false, suspended: true);

    expect($store->created)->toHaveCount(1)
        ->and($store->created[0]['correlationId'])->toBe('');

    $pendingEvents = handoffPendingEvents($events);
    expect($pendingEvents)->toHaveCount(1)
        ->and($pendingEvents[0]->correlationId)->toBe('');
});

it('does nothing for a suspended step that is not an actor handoff candidate', function (): void {
    $step = new Step('fetch-thing', null, StepTarget::http('fetchOperation'), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step], [], [], [], []);
    $document = handoffActorDocument([$workflow]);
    $context = handoffActorContext($step);

    [$handler, $store, $events] = makeHandoffOutcomeHandler(new WorkflowEngine(new HandoffMockExpressionResolver()));

    $handler->handle($document, $workflow, $step, $context, 'exec_1', false, suspended: true);

    expect($store->created)->toBe([])
        ->and(handoffPendingEvents($events))->toBe([]);
});

it('resumes an interaction step with the actor input and completes the step', function (): void {
    $step1 = new Step('wait-approval', null, StepTarget::async('receive', 'channels/approvals', new Expression('$request.body#/id')), new StepFlow(), new StepIo());
    $step2 = new Step('execute-on-approval', null, StepTarget::http('approveOperation'), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step1, $step2], [], [], [], []);
    $document = handoffActorDocument([$workflow]);

    $definitions = new InMemoryDefinitionStore();
    $definitionId = $definitions->save($document);

    $store = new HandoffMockStore();
    $store->preloaded = [
        'definitionId' => $definitionId,
        'workflowId' => 'wf_1',
        'steps' => ['wait-approval' => ['status' => StepStatus::Suspended->value]],
        'inputs' => [],
        'components' => [],
        'stepsSpent' => 0,
        'workflowCallStack' => ['wf_1'],
    ];
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-approval', 'channels/approvals');

    $events = new HandoffMockEventDispatcher();
    $queue = new HandoffMockQueueDriver();
    $observability = new ObservabilityContext(events: $events);

    $engine = new WorkflowEngine(new HandoffMockExpressionResolver(), maxRetryAttempts: 10, retryBackoffMultiplier: 1.0);
    $handler = new StepOutcomeHandler(
        $store,
        new RunControlFlow($engine, $queue, $observability),
        invoker: \Mockery::mock(SubWorkflowInvoker::class),
        evaluationEngine: new HandoffMockExpressionResolver(),
    );

    $extractor = \Mockery::mock(OutputExtractorInterface::class)->shouldReceive('extractOutputs')->andReturn([])->getMock();
    $resumer = new CorrelationResumer(
        new AsyncPorts($store, $definitions, $queue, new HandoffMockLockManager(), $extractor),
        new HandoffMockExpressionResolver(),
        $handler,
        $observability,
    );

    $resumer->resume('corr_1', ['body' => ['approved' => true]]);

    $resumedEvents = array_values(array_filter(
        $events->dispatched,
        static fn (object $event): bool => $event instanceof CorrelationResumedEvent,
    ));

    expect($store->consumed)->toContain('corr_1')
        ->and($resumedEvents)->toHaveCount(1)
        ->and($resumedEvents[0]->correlationId)->toBe('corr_1')
        ->and($store->saves['exec_1']['steps']['wait-approval']['status'])->toBe(StepStatus::Succeeded)
        ->and($queue->dispatched)->toHaveCount(1);
});
