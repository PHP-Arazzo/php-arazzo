<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\AsyncRunnerInterface;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;

function asyncRunnerDocument(): ArazzoDocument
{
    $workflow = new Workflow('async_wf', null, null, null, [], [
        new Step('one', null, new StepTarget(), new StepFlow(), new StepIo()),
        new Step('two', null, new StepTarget(), new StepFlow(dependsOn: ['one']), new StepIo()),
    ], [], [], [], []);

    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Async', null, null, '1.0.0'),
        sourceDescriptions: [],
        workflows: [$workflow],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

function asyncStubExecutor(): OperationExecutorPluginInterface
{
    return new class() implements OperationExecutorPluginInterface
    {
        public function name(): string
        {
            return 'async-stub';
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
            return StepExecutionOutcome::resolved(200, ['step' => $step->stepId], ['ok' => true]);
        }
    };
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('creates an immutable AsyncRunner implementing AsyncRunnerInterface with in-memory defaults', function (): void {
    $base = AsyncRunner::create();
    $withQueue = $base->withQueueDriver(new SyncQueueDriver());

    expect($base)->toBeInstanceOf(AsyncRunnerInterface::class)
        ->and($withQueue)->not->toBe($base);
});

it('starts, drains queued ExecuteStepJobs, and completes an execution end-to-end', function (): void {
    $queue = new SyncQueueDriver();
    $store = new InMemoryExecutionStore();

    $runner = AsyncRunner::create()
        ->withStore($store)
        ->withQueueDriver($queue)
        ->withExecutor(asyncStubExecutor());

    $executionId = $runner->start(asyncRunnerDocument(), 'async_wf', ['k' => 'v'], executionId: 'exec_async_1');

    expect($executionId)->toBe('exec_async_1')
        ->and($queue->dispatched)->toHaveCount(1);

    while ($slot = array_shift($queue->dispatched)) {
        /** @var ExecuteStepJob $job */
        $job = $slot['job'];
        $runner->handleStep($job);
    }

    expect($store->statusOf('exec_async_1'))->toBe(ExecutionStatus::Succeeded)
        ->and($store->loadState('exec_async_1'))->not->toBeNull();
});

it('resumes a persisted execution from the next incomplete step', function (): void {
    $queue = new SyncQueueDriver();
    $store = new InMemoryExecutionStore();
    $definitions = new InMemoryDefinitionStore();
    $doc = asyncRunnerDocument();
    $definitionId = $definitions->save($doc);

    $store->saveState('exec_resume_1', [
        'definitionId' => $definitionId,
        'workflowId' => 'async_wf',
        'steps' => ['one' => ['statusCode' => 200, 'status' => 'succeeded']],
        'inputs' => [],
        'components' => [],
    ]);

    $runner = AsyncRunner::create()
        ->withStore($store)
        ->withDefinitions($definitions)
        ->withQueueDriver($queue)
        ->withExecutor(asyncStubExecutor());

    $runner->resume($doc, 'exec_resume_1');

    expect($queue->dispatched)->toHaveCount(1);
    /** @var ExecuteStepJob $job */
    $job = $queue->dispatched[0]['job'];
    expect($job->step->stepId)->toBe('two');

    $runner->handleStep($job);

    expect($store->statusOf('exec_resume_1'))->toBe(ExecutionStatus::Succeeded);
});

it('throws UnknownWorkflowException when starting an unknown workflow', function (): void {
    AsyncRunner::create()->start(asyncRunnerDocument(), 'does-not-exist');
})->throws(UnknownWorkflowException::class, "Unknown workflow 'does-not-exist'.");
