<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Runner\Execution;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ActionKind;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Async\WorkerStateSynchronizer;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowPreflightRunner;
use Alama\Arazzo\Tests\Support\RecordingEventDispatcher;
use Mockery;

function makeStaticPlugin(int $statusCode = 200): OperationExecutorPluginInterface
{
    return new class($statusCode) implements OperationExecutorPluginInterface
    {
        public function __construct(private readonly int $statusCode) {}

        public function name(): string
        {
            return 'static-test';
        }

        public function priority(): int
        {
            return 0;
        }

        public function supports(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
        {
            return StepExecutionOutcome::resolved($this->statusCode, [], ['ok' => true]);
        }
    };
}

function makePassThroughLock(): LockManagerInterface
{
    return new class() implements LockManagerInterface
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
    };
}

it('falls through when WorkflowEngine encounters an unrecognized custom SuccessAction subtype', function (): void {
    $customAction = new readonly class('noop-custom', []) extends SuccessAction
    {
        /** @param list<SuccessCriterion> $criteria */
        public function __construct(string $name, array $criteria)
        {
            parent::__construct($name, ActionKind::End, $criteria);
        }
    };

    $step = StepFactory::http('s1', null, new StepFlow(onSuccess: [$customAction]), new StepIo(), operationId: 'op1');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $document = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);

    $engine = new WorkflowEngine(new EvaluationEngine(expression: new ExpressionEngine()));
    $state = ExecutionState::start('exec-1', 'def-1', 'wf1');

    $transition = $engine->transition($document, $workflow, $step, $state, criteriaMet: true);

    expect($transition->isTerminal())->toBeTrue()
        ->and($transition->status)->toBe('succeeded');
});

it('applies default-populated inputs returned by DocumentInterface::validateInputs in WorkflowPreflightRunner', function (): void {
    $workflow = new Workflow('wf1', null, null, ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'default' => 'eu']]], [], [], [], [], [], []);
    $document = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);

    $docFacade = Mockery::mock(DocumentInterface::class);
    $docFacade->shouldReceive('validateInputs')
        ->once()
        ->with($document, 'wf1', [])
        ->andReturn(new ValidationResult($document, [], [], ['region' => 'eu']));

    $runner = new WorkflowPreflightRunner(document: $docFacade);
    $validated = $runner->validate($workflow, $document, []);

    expect($validated)->toBe(['region' => 'eu']);
});

it('switches active workflow on cross-workflow goto and throws UnknownWorkflowException when invoking a missing workflow in WorkflowExecutor', function (): void {
    $step1 = StepFactory::http(
        's1',
        null,
        new StepFlow(onSuccess: [new SuccessGotoAction('jump', null, 'wf2', [])]),
        new StepIo(),
        operationId: 'op1',
    );
    $step2 = StepFactory::http('s2', null, new StepFlow(), new StepIo(), operationId: 'op2');

    $wf1 = new Workflow('wf1', null, null, null, [], [$step1], [], [], [], []);
    $wf2 = new Workflow('wf2', null, null, null, [], [$step2], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$wf1, $wf2], new Components([], [], [], []), []);

    $eval = new EvaluationEngine(expression: new ExpressionEngine());
    $executor = new WorkflowExecutor(
        new StepExecutor($eval, localExecutors: [makeStaticPlugin(200)]),
        new WorkflowEngine($eval),
    );

    $result = $executor->execute($wf1, $doc, []);
    expect($result->status)->toBe(ExecutionStatus::Succeeded)
        ->and($result->workflowId)->toBe('wf2')
        ->and(array_keys($result->stepResults))->toBe(['s1', 's2']);

    // SubWorkflowSuccessAction targeting an unknown workflowId triggers UnknownWorkflowException
    $badStep = StepFactory::http(
        'bad',
        null,
        new StepFlow(onSuccess: [new SubWorkflowSuccessAction('invoke-missing', 'missing-wf', [], [])]),
        new StepIo(),
        operationId: 'op1',
    );
    $badWf = new Workflow('wf-bad', null, null, null, [], [$badStep], [], [], [], []);
    $badDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$badWf], new Components([], [], [], []), []);

    expect(fn () => $executor->execute($badWf, $badDoc, []))->toThrow(UnknownWorkflowException::class);
});

it('replays prior step attempt counts when hydrating state in WorkerStateSynchronizer', function (): void {
    $store = new InMemoryExecutionStore();
    $sync = new WorkerStateSynchronizer($store);

    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'op1');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $context = (new WorkflowContext('def-1', [], [], [], 'wf1', 'exec-replay'))
        ->withStepResult('s1', ['statusCode' => 500, 'attempts' => 3]);

    $state = $sync->hydrateState($context, $workflow, $step, ExecutionPolicy::default());

    expect($state->attemptFor('s1'))->toBe(2);
});

it('handles terminal failure, workflow-only goto, and unknown follow-up workflow in StepExecutionWorker', function (): void {
    $eval = new EvaluationEngine(expression: new ExpressionEngine());
    $queue = new class() implements QueueDriverInterface
    {
        /** @var list<ExecuteStepJob> */
        public array $dispatched = [];

        public function dispatch(object $job, ?int $delaySeconds = null): void
        {
            if ($job instanceof ExecuteStepJob) {
                $this->dispatched[] = $job;
            }
        }
    };

    // 1. Terminal failure (status 500 fails == 200 criterion)
    $failStep = StepFactory::http(
        'fail-step',
        null,
        new StepFlow(),
        new StepIo(successCriteria: [new SuccessCriterion(null, '$statusCode == 200', CriterionType::Simple)]),
        operationId: 'op1',
    );
    $failWf = new Workflow('wf-fail', null, null, null, [], [$failStep], [], [], [], []);
    $failDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$failWf], new Components([], [], [], []), []);

    $store = new InMemoryExecutionStore();
    $defs = new InMemoryDefinitionStore();
    $defs->save($failDoc, 'def-fail');
    $events = new RecordingEventDispatcher();
    OperationExecutorRegistry::reset();
    $registry = OperationExecutorRegistry::create();
    $registry->register(makeStaticPlugin(500));

    $worker = new StepExecutionWorker(
        new AsyncPorts($store, $defs, $queue, makePassThroughLock()),
        $eval,
        $registry,
        new RunControlFlow(new WorkflowEngine($eval), $queue, new ObservabilityContext(events: $events)),
    );

    $worker->handle(new ExecuteStepJob($failStep, new WorkflowContext('def-fail', [], [], [], 'wf-fail', 'exec-fail')));
    expect(array_filter($events->events, static fn (object $e): bool => $e instanceof RunFailedEvent))->not->toBeEmpty();

    // 2. Follow-up workflow-only goto (transition->stepId === null dispatches first step of target workflow)
    $gotoStep = StepFactory::http(
        'jump-step',
        null,
        new StepFlow(onSuccess: [new SuccessGotoAction('to-wf2', null, 'wf2', [])]),
        new StepIo(),
        operationId: 'op1',
    );
    $targetStep = StepFactory::http('wf2-first', null, new StepFlow(), new StepIo(), operationId: 'op2');
    $wf1 = new Workflow('wf1', null, null, null, [], [$gotoStep], [], [], [], []);
    $wf2 = new Workflow('wf2', null, null, null, [], [$targetStep], [], [], [], []);
    $gotoDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$wf1, $wf2], new Components([], [], [], []), []);
    $defs->save($gotoDoc, 'def-goto');

    OperationExecutorRegistry::reset();
    $okRegistry = OperationExecutorRegistry::create();
    $okRegistry->register(makeStaticPlugin(200));

    $okWorker = new StepExecutionWorker(
        new AsyncPorts($store, $defs, $queue, makePassThroughLock()),
        $eval,
        $okRegistry,
        new RunControlFlow(new WorkflowEngine($eval), $queue, new ObservabilityContext(events: $events)),
    );
    $okWorker->handle(new ExecuteStepJob($gotoStep, new WorkflowContext('def-goto', [], [], [], 'wf1', 'exec-goto')));
    expect($queue->dispatched)->toHaveCount(1)
        ->and($queue->dispatched[0]->step->stepId)->toBe('wf2-first');

    // 3. Transition targeting unknown workflow (SubWorkflowSuccessAction -> Transition::invoke)
    $unknownStep = StepFactory::http(
        'invoke-step',
        null,
        new StepFlow(onSuccess: [new SubWorkflowSuccessAction('invoke-ghost', 'ghost-wf', [], [])]),
        new StepIo(),
        operationId: 'op1',
    );
    $unknownWf = new Workflow('wf-unknown', null, null, null, [], [$unknownStep], [], [], [], []);
    $unknownDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$unknownWf], new Components([], [], [], []), []);
    $defs->save($unknownDoc, 'def-unknown');

    $events3 = new RecordingEventDispatcher();
    $unknownWorker = new StepExecutionWorker(
        new AsyncPorts($store, $defs, $queue, makePassThroughLock()),
        $eval,
        $okRegistry,
        new RunControlFlow(new WorkflowEngine($eval), $queue, new ObservabilityContext(events: $events3)),
    );
    $unknownWorker->handle(new ExecuteStepJob($unknownStep, new WorkflowContext('def-unknown', [], [], [], 'wf-unknown', 'exec-unknown')));
    OperationExecutorRegistry::reset();
    expect(array_filter($events3->events, static fn (object $e): bool => $e instanceof RunFailedEvent))->toHaveCount(1);
});
