<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
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
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\Events\CorrelationPendingEvent;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;

class WorkerMockLockManager implements LockManagerInterface
{
    public int $acquireCount = 0;

    /** @var list<string> */
    public array $keysUsed = [];

    public function acquire(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $this->acquireCount++;
        $this->keysUsed[] = $key;

        return $callback();
    }

    public function tryAcquire(string $key, int $ttlSeconds): bool
    {
        $this->acquireCount++;
        $this->keysUsed[] = $key;

        return true;
    }

    public function release(string $key): void {}
}

class WorkerMockExpressionResolver implements EvaluationEngineInterface
{
    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed
    {
        return $expression->raw;
    }

    public function evaluateSuccessCriteria(Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): bool
    {
        return true;
    }

    /**
     * @return list<CriterionResult>
     */
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

class WorkerMockQueueDriver implements QueueDriverInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $job, int $delaySeconds = 0): void
    {
        $this->dispatched[] = $job;
    }
}

class WorkerHandoffEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}

class WorkerHandoffExpressionResolver extends WorkerMockExpressionResolver
{
    public ?Closure $correlationHook = null;

    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed
    {
        if ($this->correlationHook !== null) {
            return ($this->correlationHook)($expression->raw);
        }

        return parent::evaluate($expression, $context);
    }
}

class WorkerHandoffDefinitionStore implements DefinitionStoreInterface
{
    public function __construct(private readonly ArazzoDocument $document) {}

    public function save(ArazzoDocument $document, ?string $definitionId = null): string
    {
        return $definitionId ?? 'test-def';
    }

    public function find(string $definitionId): ?ArazzoDocument
    {
        return $this->document;
    }
}

class WorkerHandoffSuspendingExecutor implements OperationExecutorPluginInterface
{
    public function name(): string
    {
        return 'handoff';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return true;
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        return StepExecutionOutcome::suspended();
    }
}

/**
 * @return array{0: StepExecutionWorker, 1: WorkerHandoffEventDispatcher, 2: InMemoryExecutionStore}
 */
function makeHandoffWorker(ArazzoDocument $document, WorkerHandoffExpressionResolver $resolver): array
{
    $events = new WorkerHandoffEventDispatcher();
    $store = new InMemoryExecutionStore();
    $queue = new WorkerMockQueueDriver();

    OperationExecutorRegistry::reset();
    $executors = OperationExecutorRegistry::create();
    $executors->register(new WorkerHandoffSuspendingExecutor());

    $ports = new AsyncPorts(
        store: $store,
        definitions: new WorkerHandoffDefinitionStore($document),
        queueDriver: $queue,
        lockManager: new WorkerMockLockManager(),
    );

    $controlFlow = new RunControlFlow(
        new WorkflowEngine($resolver, maxRetryAttempts: 10, retryBackoffMultiplier: 1.0),
        $queue,
        new ObservabilityContext(events: $events),
    );

    $worker = new StepExecutionWorker($ports, $resolver, $executors, $controlFlow);

    return [$worker, $events, $store];
}

it('emits an empty correlation id for a receive step whose correlation expression resolves to a non-scalar', function (): void {
    $step = new Step('wait-approval', null, StepTarget::async('receive', 'channels/approvals', new Expression('$request.body#/id')), new StepFlow(), new StepIo());
    $workflow = new Workflow('wf_1', null, null, null, [], [$step], [], [], [], []);
    $document = new ArazzoDocument('1.0.0', new Info('Test', null, null, '1.0.0'), [], [$workflow], new Components([], [], [], []), []);

    $resolver = new WorkerHandoffExpressionResolver();
    $resolver->correlationHook = static fn (string $raw): mixed => new \stdClass();

    [$worker, $events] = makeHandoffWorker($document, $resolver);

    $worker->handle(new ExecuteStepJob($step, new WorkflowContext('test-def', [], [], [], 'wf_1', 'exec_1')));

    $pendingEvents = array_values(array_filter(
        $events->dispatched,
        static fn (object $event): bool => $event instanceof CorrelationPendingEvent,
    ));

    expect($pendingEvents)->toHaveCount(1)
        ->and($pendingEvents[0]->correlationId)->toBe('')
        ->and($pendingEvents[0]->channelPath)->toBe('channels/approvals');
});

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});
