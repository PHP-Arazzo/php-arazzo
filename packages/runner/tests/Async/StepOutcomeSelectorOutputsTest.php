<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\Async\SubWorkflowInvoker;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;

it('resolves a Selector output through SelectorEvaluator', function () {
    $exprEngine = Mockery::mock(EvaluationEngineInterface::class);
    $exprEngine->shouldReceive('evaluateSelector')->once()->andReturn('bar');

    $store = Mockery::mock(ExecutionStoreInterface::class);
    $store->shouldReceive('saveState');
    $store->shouldReceive('hasPendingCorrelations')->once()->andReturn(false);
    $store->shouldReceive('markCompleted')->once();

    $handler = new StepOutcomeHandler(
        $store,
        new RunControlFlow(new WorkflowEngine(Mockery::mock(EvaluationEngineInterface::class)), Mockery::mock(QueueDriverInterface::class)),
        invoker: Mockery::mock(SubWorkflowInvoker::class),
        evaluationEngine: $exprEngine,
    );

    $step = StepFactory::http(
        stepId: 'step1',
        description: 'Test step',
        flow: new StepFlow(),
        io: new StepIo(outputs: ['id' => new Selector(null, '$.foo', ExpressionType::JsonPath)]),
        operationId: 'op1',
    );

    $workflow = new Workflow('test_wf', null, null, null, [], [$step], [], [], [], []);
    $document = new ArazzoDocument('1.0.0', new Info('Title', null, null, '1.1.0'), [], [], new Components([], [], [], []), []);
    $context = new WorkflowContext('def_1', [], ['step1' => []], [], 'test_wf', 'exec_1');

    $handler->handle($document, $workflow, $step, $context, 'exec_1', true);
});
