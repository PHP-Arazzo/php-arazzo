<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Support\Events\Dispatcher\SimpleEventDispatcher;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Async\CorrelationResumer;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\Async\SubWorkflowInvoker;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\Defaults\InMemoryLockManager;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Psr\Log\AbstractLogger;

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('handles missing definition, missing workflow, preflight failures, unresolved executors, and runtime exceptions in StepExecutionWorker', function (): void {
    $logs = [];
    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<string> $logs */
        public function __construct(private array &$logs) {}

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->logs[] = (string) $message;
        }
    };

    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $store = new InMemoryExecutionStore();
    $definitions = new InMemoryDefinitionStore();
    $queue = new SyncQueueDriver();
    $events = new SimpleEventDispatcher();
    $failedEvents = [];
    $events->subscribe(RunFailedEvent::class, function (RunFailedEvent $e) use (&$failedEvents): void {
        $failedEvents[] = $e->category;
    });

    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), 'op1');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);
    $defId = $definitions->save($doc);

    $ports = new AsyncPorts($store, $definitions, $queue, new InMemoryLockManager());
    $obs = new ObservabilityContext(events: $events, logger: $logger);
    $controlFlow = new RunControlFlow(new WorkflowEngine($engine), $queue, $obs);

    // 1. Already succeeded step is skipped silently.
    $workerNoExec = new StepExecutionWorker($ports, $engine, null, $controlFlow);
    $doneCtx = (new WorkflowContext($defId, [], [], [], 'wf1', 'e_done'))->withStepStatus('s1', StepStatus::Succeeded);
    $workerNoExec->handle(new ExecuteStepJob($step, $doneCtx));

    // 2. Missing definition logs error and returns cleanly.
    $workerNoExec->handle(new ExecuteStepJob($step, new WorkflowContext('missing_def', [], [], [], 'wf1', 'e_nodef')));
    expect($logs)->toContain('Workflow definition not found for execution.');

    // 3. Missing workflow logs error and returns cleanly.
    $workerNoExec->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'missing_wf', 'e_nowf')));
    expect($logs)->toContain('Workflow not found in definition for execution.');

    // 4. Null executor registry throws ExecutionException and emits execution failure event.
    expect(fn () => $workerNoExec->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'wf1', 'e_nullreg'))))
        ->toThrow(ExecutionException::class);

    // 5. Empty injected executor registry throws LogicException.
    $emptyWorker = new StepExecutionWorker($ports, $engine, OperationExecutorRegistry::create(), $controlFlow);
    expect(fn () => $emptyWorker->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'wf1', 'e_emptyreg'))))
        ->toThrow(LogicException::class);

    // 6. Preflight failure on fresh run throws PreflightFailureException with authoring category.
    $failingResolver = Mockery::mock(OperationResolverInterface::class);
    $failingResolver->shouldReceive('supportsStep')->andReturn(false);
    $preflightFlow = new RunControlFlow(
        new WorkflowEngine($engine),
        $queue,
        $obs,
        preflight: new Preflight(new OperationResolvers([$failingResolver])),
    );
    $preflightWorker = new StepExecutionWorker($ports, $engine, OperationExecutorRegistry::create(), $preflightFlow);
    expect(fn () => $preflightWorker->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'wf1', 'e_preflight'))))
        ->toThrow(PreflightFailureException::class);

    // 7. SchemaValidationException thrown by executor is classified as schema.
    $schemaThrowingRegistry = OperationExecutorRegistry::create()->snapshot([
        new class() implements OperationExecutorPluginInterface
        {
            public function name(): string
            {
                return 'schema-boom';
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
                throw new SchemaValidationException('s1', [['path' => '/x', 'message' => 'bad']]);
            }
        },
    ]);
    $schemaWorker = new StepExecutionWorker($ports, $engine, $schemaThrowingRegistry, $controlFlow);
    expect(fn () => $schemaWorker->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'wf1', 'e_schema'))))
        ->toThrow(SchemaValidationException::class);

    expect($failedEvents)->toContain('execution', 'authoring', 'schema');
});

it('handles missing step and thrown exceptions inside CorrelationResumer', function (): void {
    $logs = [];
    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<string> $logs */
        public function __construct(private array &$logs) {}

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->logs[] = (string) $message;
        }
    };

    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $store = new InMemoryExecutionStore();
    $definitions = new InMemoryDefinitionStore();
    $step = new Step('real-step', null, StepTarget::async('receive', 'chan/a'), new StepFlow(), new StepIo());
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [new Workflow('wf1', null, null, null, [], [$step], [], [], [], [])], new Components([], [], [], []), []);
    $defId = $definitions->save($doc);

    $store->saveState('e1', ['definitionId' => $defId, 'workflowId' => 'wf1', 'steps' => [], 'inputs' => [], 'components' => []]);
    $store->saveCorrelation('corr_missing_step', 'e1', 'ghost-step', 'chan/a');

    $ports = new AsyncPorts($store, $definitions, new SyncQueueDriver(), new InMemoryLockManager());
    $outcomeHandler = Mockery::mock(StepOutcomeHandler::class);
    $resumer = new CorrelationResumer($ports, $engine, $outcomeHandler, new ObservabilityContext(logger: $logger));

    $resumer->resume('corr_missing_step', ['body' => []]);
    expect($logs)->toContain('Step missing on correlation resume.');

    // Exception thrown inside locked callback is recorded on span and rethrown.
    $store->saveCorrelation('corr_boom', 'e1', 'real-step', 'chan/a');
    $outcomeHandler->shouldReceive('handle')->once()->andThrow(new RuntimeException('handler boom'));
    expect(fn () => $resumer->resume('corr_boom', ['body' => []]))->toThrow(RuntimeException::class, 'handler boom');
});

it('covers SubWorkflowInvoker missing definition and selector parameter binding', function (): void {
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $definitions = new InMemoryDefinitionStore();

    $executor = Mockery::mock(WorkflowExecutor::class);
    $invoker = new SubWorkflowInvoker($definitions, $executor, $engine);

    $action = new SubWorkflowSuccessAction('call', 'child', [
        'fromSelector' => new Selector(null, '$.inputs.a', ExpressionType::JsonPath),
    ], []);

    // Missing definition throws ExecutionException.
    expect(fn () => $invoker->invoke($action, new WorkflowContext('unknown_def')))
        ->toThrow(ExecutionException::class);

    // Selector parameter resolves cleanly when definition exists.
    $childWf = new Workflow('child', null, null, null, [], [], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$childWf], new Components([], [], [], []), []);
    $defId = $definitions->save($doc);

    $executor->shouldReceive('execute')->once()->andReturn(new WorkflowExecutionResult('child', ExecutionStatus::Succeeded, [], []));
    $result = $invoker->invoke($action, new WorkflowContext($defId, ['a' => 1]));

    expect($result->status)->toBe('succeeded');
});
