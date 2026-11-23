<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowFailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\Async\SubWorkflowInvoker;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Exceptions\GotoTargetNotFoundException;
use Alama\Arazzo\Runner\Exceptions\WorkflowCycleException;
use Alama\Arazzo\Runner\Exceptions\WorkflowDepthExceededException;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\Data\RunnerOptions;
use Alama\Arazzo\Runner\Execution\Data\SubWorkflowResult;
use Alama\Arazzo\Runner\Execution\Defaults\InMemoryLockManager;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\ExecutionGraphFactory;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('rehydrates ExecutionState from persisted executionId payload and ignores non-array step records in StepExecutionWorker', function (): void {
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $store = new InMemoryExecutionStore();
    $definitions = new InMemoryDefinitionStore();
    $queue = new SyncQueueDriver();

    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), 'op1');
    $workflow = new Workflow('wf1', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);
    $defId = $definitions->save($doc);

    $store->saveState('e_rehydrate', [
        'executionId' => 'e_rehydrate',
        'definitionId' => $defId,
        'workflowId' => 'wf1',
        'steps' => ['corrupt' => 'not-an-array'],
        'inputs' => [],
        'components' => [],
    ]);

    $registry = OperationExecutorRegistry::create()->snapshot([
        new class() implements OperationExecutorPluginInterface
        {
            public function name(): string
            {
                return 'ok';
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
                return StepExecutionOutcome::resolved(200, [], []);
            }
        },
    ]);

    $worker = new StepExecutionWorker(
        new AsyncPorts($store, $definitions, $queue, new InMemoryLockManager()),
        $engine,
        $registry,
        new RunControlFlow(new WorkflowEngine($engine), $queue),
    );

    $worker->handle(new ExecuteStepJob($step, new WorkflowContext($defId, [], [], [], 'wf1', 'e_rehydrate')));

    expect($store->statusOf('e_rehydrate'))->toBe(ExecutionStatus::Succeeded);
});

it('handles expression/literal outputs, reusable sub-workflow failure actions, call-stack merging, and downstream dispatch in StepOutcomeHandler', function (): void {
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $store = new InMemoryExecutionStore();
    $queue = new SyncQueueDriver();

    $subFailAction = new SubWorkflowFailureAction('recover', 'recovery_wf', [], []);
    $components = new Components([], [], [], ['recoverRef' => $subFailAction]);

    $stepA = StepFactory::http(
        'A',
        null,
        new StepFlow(onFailure: [new Reusable('$components.failureActions.recoverRef')]),
        new StepIo(outputs: [
            'exprOut' => new Expression('$inputs.name'),
            'litOut' => 'constant',
        ]),
        'opA',
    );
    $stepB = StepFactory::http('B', null, new StepFlow(dependsOn: ['A']), new StepIo(), 'opB');
    $workflow = new Workflow('wf1', null, null, null, [], [$stepA, $stepB], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], $components, []);

    $invoker = Mockery::mock(SubWorkflowInvoker::class);
    $invoker->shouldReceive('invoke')->once()->andReturn(new SubWorkflowResult(
        outputs: ['fixed' => true],
        status: ExecutionStatus::Succeeded->value,
        childRunId: 'child_1',
        stepsSpent: 2,
        workflowCallStack: ['wf1', 'recovery_wf'],
    ));

    $handler = new StepOutcomeHandler(
        $store,
        new RunControlFlow(new WorkflowEngine($engine), $queue),
        $invoker,
        $engine,
    );

    $ctx = (new WorkflowContext('def_1', ['name' => 'alice']))
        ->withWorkflowId('wf1')
        ->withExecutionId('e_sub');

    $handler->handle($doc, $workflow, $stepA, $ctx, 'e_sub', false);

    expect($queue->dispatched)->toHaveCount(1)
        ->and($queue->dispatched[0]['job']->step->stepId)->toBe('B')
        ->and($queue->dispatched[0]['job']->context->getWorkflowCallStack())->toBe(['wf1', 'recovery_wf'])
        ->and($store->loadState('e_sub')['steps']['A']['outputs']['exprOut'])->toBe('alice')
        ->and($store->loadState('e_sub')['steps']['A']['outputs']['litOut'])->toBe('constant');
});

it('covers WorkflowEngine goto parameters, reusable validation errors, cycle/depth guards, dependency skipping, and failing output evaluation', function (): void {
    $realEngine = new EvaluationEngine(expression: ExpressionEngine::create());
    $wfEngine = new WorkflowEngine($realEngine);

    // 1. Goto with expression, literal, and Reusable parameters targeting a second workflow.
    $gotoAction = new SuccessGotoAction('jump', null, 'wf2', [], [
        new Reusable('$components.parameters.shared'),
        new Parameter('fromExpr', ParameterIn::Query, new Expression('$inputs.seed')),
        new Parameter('fromLit', ParameterIn::Query, 'static'),
    ]);
    $stepA = StepFactory::http('A', null, new StepFlow(onSuccess: [$gotoAction]), new StepIo(), 'opA');
    $wf1 = new Workflow('wf1', null, null, null, [], [$stepA], [], [], [], []);
    $wf2 = new Workflow('wf2', null, null, null, [], [StepFactory::http('targetStep', null, new StepFlow(), new StepIo(), 'opT')], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$wf1, $wf2], new Components([], [], [], []), []);

    $t = $wfEngine->transition($doc, $wf1, $stepA, ExecutionState::start('e1', 'd1', 'wf1', ['seed' => 99]), true);
    expect($t->workflowId)->toBe('wf2')
        ->and($t->state->inputs['fromExpr'])->toBe(99)
        ->and($t->state->inputs['fromLit'])->toBe('static');

    // 2. Reusable action with wrong prefix throws GotoTargetNotFoundException.
    $badPrefixStep = StepFactory::http('badP', null, new StepFlow(onSuccess: [new Reusable('$components.failureActions.x')]), new StepIo(), 'op');
    $badPrefixWf = new Workflow('wfBadP', null, null, null, [], [$badPrefixStep], [], [], [], []);
    $badPrefixDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$badPrefixWf], new Components([], [], [], []), []);
    expect(fn () => $wfEngine->transition($badPrefixDoc, $badPrefixWf, $badPrefixStep, ExecutionState::start('e', 'd', 'wfBadP'), true))
        ->toThrow(GotoTargetNotFoundException::class);

    // 3. Reusable action pointing at missing component throws GotoTargetNotFoundException.
    $missingRefStep = StepFactory::http('badM', null, new StepFlow(onSuccess: [new Reusable('$components.successActions.missing')]), new StepIo(), 'op');
    $missingRefWf = new Workflow('wfBadM', null, null, null, [], [$missingRefStep], [], [], [], []);
    $missingRefDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$missingRefWf], new Components([], [], [], []), []);
    expect(fn () => $wfEngine->transition($missingRefDoc, $missingRefWf, $missingRefStep, ExecutionState::start('e', 'd', 'wfBadM'), true))
        ->toThrow(GotoTargetNotFoundException::class);

    // 4. Workflow cycle and workflow depth exceeded guards on SubWorkflowSuccessAction.
    $cycleStep = StepFactory::http('cyc', null, new StepFlow(onSuccess: [new SubWorkflowSuccessAction('c', 'wfCyc', [], [])]), new StepIo(), 'op');
    $cycleWf = new Workflow('wfCyc', null, null, null, [], [$cycleStep], [], [], [], []);
    $cycleDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$cycleWf], new Components([], [], [], []), []);
    expect(fn () => $wfEngine->transition($cycleDoc, $cycleWf, $cycleStep, ExecutionState::start('e', 'd', 'wfCyc'), true))
        ->toThrow(WorkflowCycleException::class);

    $depthStep = StepFactory::http('dep', null, new StepFlow(onSuccess: [new SubWorkflowSuccessAction('d', 'childWf', [], [])]), new StepIo(), 'op');
    $depthWf = new Workflow('wfDep', null, null, null, [], [$depthStep], [], [], [], []);
    $depthDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$depthWf], new Components([], [], [], []), []);
    expect(fn () => $wfEngine->transition($depthDoc, $depthWf, $depthStep, ExecutionState::start('e', 'd', 'wfDep', maxWorkflowDepth: 1), true))
        ->toThrow(WorkflowDepthExceededException::class);

    // 5. Next-runnable skips blocked step whose dependency is not yet satisfied.
    $s1 = StepFactory::http('s1', null, new StepFlow(), new StepIo(), 'op1');
    $s2 = StepFactory::http('s2', null, new StepFlow(), new StepIo(), 'op2');
    $s3 = StepFactory::http('s3', null, new StepFlow(dependsOn: ['s2']), new StepIo(), 'op3');
    $branchWf = new Workflow('wfBranch', null, null, null, [], [$s1, $s3, $s2], [], [], [], []);
    $branchDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$branchWf], new Components([], [], [], []), []);
    $next = $wfEngine->transition($branchDoc, $branchWf, $s1, ExecutionState::start('e', 'd', 'wfBranch'), true);
    expect($next->stepId)->toBe('s2');

    // 6. evaluateWorkflowOutputs catches throwable and returns null for that output.
    $throwingEval = Mockery::mock(EvaluationEngineInterface::class);
    $throwingEval->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('eval boom'));
    $outWf = new Workflow('wfOut', null, null, null, [], [], [], [], ['broken' => new Expression('$steps.x.outputs.y')], []);
    $evaluated = (new WorkflowEngine($throwingEval))->evaluateWorkflowOutputs($outWf, ExecutionState::start('e', 'd', 'wfOut'));
    expect($evaluated)->toBe(['broken' => null]);
});

it('handles diamond workflow dependency memoization and empty workflows in Runner / WorkflowExecutor', function (): void {
    $stub = new class() implements OperationExecutorPluginInterface
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'counter';
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
            $this->calls++;

            return StepExecutionOutcome::resolved(200, ['n' => $this->calls], []);
        }
    };

    // Empty workflow returns Succeeded immediately with 0 steps spent.
    $emptyWf = new Workflow('empty', null, null, null, [], [], [], [], [], []);
    $baseWf = new Workflow('base', null, null, null, [], [StepFactory::http('b', null, new StepFlow(), new StepIo(), 'opB')], [], [], [], []);
    $mainWf = new Workflow('main', null, null, null, ['base'], [], [], [], [], []);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$emptyWf, $baseWf, $mainWf], new Components([], [], [], []), []);

    $emptyRes = Runner::create()->withExecutor($stub)->run($doc, 'empty');
    expect($emptyRes->isSucceeded())->toBeTrue()
        ->and($emptyRes->stepsSpent)->toBe(0);

    // Pre-populated dependency data is memoized and skipped.
    $mainRes = Runner::create()->withExecutor($stub)->run($doc, 'main');
    expect($mainRes->isSucceeded())->toBeTrue()
        ->and($stub->calls)->toBe(1);

    // Reusable SubWorkflowSuccessAction in StepOutcomeHandler + multi-workflow RetryAction target + memoized WorkflowExecutor dependency.
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $wfEngine = new WorkflowEngine($engine);
    $subOk = new SubWorkflowSuccessAction('callOk', 'base', [], []);
    $sOk = StepFactory::http('sOk', null, new StepFlow(onSuccess: [new Reusable('$components.successActions.okRef')]), new StepIo(), 'opOk');
    $okWf = new Workflow('okWf', null, null, null, [], [$sOk], [], [], [], []);
    $okDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$baseWf, $okWf], new Components([], [], ['okRef' => $subOk], []), []);
    $invoker = Mockery::mock(SubWorkflowInvoker::class);
    $invoker->shouldReceive('invoke')->once()->andReturn(new SubWorkflowResult([], ExecutionStatus::Succeeded->value, 'c2'));
    $store = new InMemoryExecutionStore();
    $handler = new StepOutcomeHandler($store, new RunControlFlow($wfEngine, new SyncQueueDriver()), $invoker, $engine);
    $handler->handle($okDoc, $okWf, $sOk, (new WorkflowContext('d'))->withWorkflowId('okWf')->withExecutionId('e_ok'), 'e_ok', true);
    expect($store->statusOf('e_ok'))->toBe(ExecutionStatus::Succeeded);

    // Multi-workflow target() lookup where target workflow is not index 0.
    $retryStep = StepFactory::http('r', null, new StepFlow(onFailure: [new RetryAction('ret', 0, 2, 'r', null, [])]), new StepIo(), 'opR');
    $wfSecond = new Workflow('second', null, null, null, [], [$retryStep], [], [], [], []);
    $multiDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$baseWf, $wfSecond], new Components([], [], [], []), []);
    $retryTrans = $wfEngine->transition($multiDoc, $wfSecond, $retryStep, ExecutionState::start('e', 'd', 'second'), false);
    expect($retryTrans->stepId)->toBe('r');

    // nextRunnable skips a step whose declared dependency is not yet completed in state.
    $depA = StepFactory::http('depA', null, new StepFlow(), new StepIo(), 'opA');
    $depB = StepFactory::http('depB', null, new StepFlow(dependsOn: ['depA']), new StepIo(), 'opB');
    $depC = StepFactory::http('depC', null, new StepFlow(), new StepIo(), 'opC');
    $depWf = new Workflow('depWf', null, null, null, [], [$depA, $depB, $depC], [], [], [], []);
    expect($wfEngine->nextRunnableStep($depWf, ExecutionState::start('e', 'd', 'depWf'), 'depA'))->toBe('depC');

    // Memoized dependency workflow in WorkflowExecutor when caller context already carries dependency outputs.
    $wfExec = (new ExecutionGraphFactory(
        Document::create(),
        $engine,
        (new RunnerOptions())->withExecutor($stub),
    ))->createWorkflowExecutor();
    $preSeededCtx = (new WorkflowContext('main', [], [], [], 'main', 'e_memo'))
        ->withWorkflowData('base', ['outputs' => ['n' => 1]]);
    $memoRes = $wfExec->execute($mainWf, $doc, [], $preSeededCtx);
    expect($memoRes->isSucceeded())->toBeTrue()
        ->and($stub->calls)->toBe(1);
});
