<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Runner\Sync;

use Alama\Arazzo\Contracts\Exceptions\TransportException;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Events\StepFailedEvent;
use Alama\Arazzo\Runner\Events\StepRetriedEvent;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Alama\Arazzo\Tests\Support\RecordingEventDispatcher;
use Mockery;

it('bypasses criteria evaluation and records null statusCode on transport failure outcome', function (): void {
    $failingPlugin = new class() implements OperationExecutorPluginInterface
    {
        public function name(): string
        {
            return 'failing';
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
            return StepExecutionOutcome::failed(
                TransportException::connectionRefused('https://api.test/status', 'GET'),
                'transport',
                ['method' => 'GET', 'url' => 'https://api.test/status'],
            );
        }
    };

    $mockEngine = Mockery::mock(EvaluationEngineInterface::class);
    $mockEngine->shouldNotReceive('evaluateSuccessCriteria');

    $stepExecutor = new StepExecutor($mockEngine, localExecutors: [$failingPlugin]);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'getOp');
    $context = new WorkflowContext('wf1', [], [], [], 'wf1', 'exec1');

    [$updatedContext, $success] = $stepExecutor->execute($step, $context, $doc);

    expect($success)->toBeFalse()
        ->and($updatedContext->getStepStatusCode('s1'))->toBeNull()
        ->and($updatedContext->getStepRecord('s1')['failureCategory'] ?? null)->toBe('transport')
        ->and($updatedContext->getStepRecord('s1')['exception'] ?? null)->toBeInstanceOf(TransportException::class);
});

it('dispatches StepFailedEvent and RunFailedEvent with typed TransportException cause', function (): void {
    $transportError = TransportException::timeout('https://api.test/timeout', 'POST');
    $failingPlugin = new class($transportError) implements OperationExecutorPluginInterface
    {
        public function __construct(private TransportException $error) {}

        public function name(): string
        {
            return 'failing';
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
            return StepExecutionOutcome::failed($this->error, 'transport');
        }
    };

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $events = new RecordingEventDispatcher();
    $stepExecutor = new StepExecutor($engine, localExecutors: [$failingPlugin]);
    $workflowExecutor = new WorkflowExecutor(
        $stepExecutor,
        new WorkflowEngine($engine),
        events: $events,
    );

    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'postOp');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);

    $result = $workflowExecutor->execute($workflow, $doc, []);

    expect($result->status)->toBe(ExecutionStatus::Failed);

    $stepFailed = null;
    $runFailed = null;
    foreach ($events->events as $event) {
        if ($event instanceof StepFailedEvent) {
            $stepFailed = $event;
        }
        if ($event instanceof RunFailedEvent) {
            $runFailed = $event;
        }
    }

    expect($stepFailed)->not->toBeNull()
        ->and($stepFailed->category)->toBe('transport')
        ->and($stepFailed->cause)->toBe($transportError)
        ->and($runFailed)->not->toBeNull()
        ->and($runFailed->category)->toBe('transport')
        ->and($runFailed->cause)->toBe($transportError);
});

it('honours onFailure retry on transport failure before failing', function (): void {
    $attempts = 0;
    $transportError = TransportException::networkError('https://api.test/retry', 'GET', 'Temporary network glitch');

    $retryingPlugin = new class($attempts, $transportError) implements OperationExecutorPluginInterface
    {
        public function __construct(public int &$attempts, private TransportException $error) {}

        public function name(): string
        {
            return 'retrying';
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
            $this->attempts++;

            return StepExecutionOutcome::failed($this->error, 'transport');
        }
    };

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $events = new RecordingEventDispatcher();
    $stepExecutor = new StepExecutor($engine, localExecutors: [$retryingPlugin]);
    $workflowExecutor = new WorkflowExecutor(
        $stepExecutor,
        new WorkflowEngine($engine),
        events: $events,
    );

    // Step with onFailure retry limit: 2 (total 3 attempts)
    $retryAction = new RetryAction('retryOnFail', null, 2, null, null, []);
    $step = StepFactory::http('s1', null, new StepFlow(onFailure: [$retryAction]), new StepIo(), operationId: 'retryOp');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);

    $result = $workflowExecutor->execute($workflow, $doc, []);

    expect($attempts)->toBe(3)
        ->and($result->status)->toBe(ExecutionStatus::Failed);

    $retriedEvents = array_filter($events->events, static fn ($e) => $e instanceof StepRetriedEvent);
    expect($retriedEvents)->toHaveCount(2);
});
