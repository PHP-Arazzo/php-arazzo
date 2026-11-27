<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Execution;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Runner\Execution\Data\RunnerOptions;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Sync\ExecutionGraphFactory;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Alama\Arazzo\Tests\Support\FakeOpenApiExecutor;
use Alama\Arazzo\Tests\Support\FakeResponseValidator;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use GuzzleHttp\Psr7\Response;
use Mockery;

require_once __DIR__.'/../Support/FakeSeams.php';

function subWorkflowDocument(): ArazzoDocument
{
    $child = new Workflow(
        workflowId: 'child',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [
            StepFactory::http(
                'fetch',
                null,
                new StepFlow(),
                new StepIo(outputs: ['id' => new Expression('$response.body#/id')]),
                'get-order',
            ),
        ],
        successActions: [],
        failureActions: [],
        outputs: ['order_id' => new Expression('$steps.fetch.outputs.id')],
        parameters: [],
    );

    $parent = new Workflow(
        workflowId: 'parent',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [
            StepFactory::workflow(
                'call-child',
                null,
                new StepFlow(),
                new StepIo(),
                'child',
            ),
        ],
        successActions: [],
        failureActions: [],
        outputs: ['order_id' => new Expression('$steps.call-child.outputs.order_id')],
        parameters: [],
    );

    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('sub-workflow', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('orders', 'https://api.test/openapi.json', SourceType::Openapi)],
        workflows: [$parent, $child],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

function subWorkflowGraph(OperationExecutorRegistry $registry): WorkflowExecutor
{
    $documents = Mockery::mock(DocumentInterface::class);

    $resolver = Mockery::mock(OpenApiOperationResolver::class);
    $resolver->shouldReceive('supportsStep')->andReturn(true);
    $resolver->shouldReceive('resolve')->andReturn(new OpenApiOperationHandle(
        new ResolvedOperation(
            source: new SourceDescription('orders', 'https://api.test/openapi.json', SourceType::Openapi),
            normalized: new NormalizedOpenApiOperation(path: '/orders', method: 'get'),
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        new OpenApi([]),
        new Operation([]),
    ));

    $evaluation = new EvaluationEngine(expression: new ExpressionEngine());
    $httpPlugin = new HttpStepExecutor(
        new FakeOpenApiExecutor(new Response(200, ['Content-Type' => 'application/json'], '{"id":"ord_1"}')),
        $resolver,
        $evaluation,
        new StepOutputExtractor($resolver, $evaluation, new ExpressionEngine()),
        new FakeResponseValidator(),
    );

    $options = (new RunnerOptions())
        ->withExecutor($httpPlugin)
        ->withOperationResolver($resolver);

    return (new ExecutionGraphFactory($documents, $evaluation, $options))
        ->createWorkflowExecutor($registry);
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('snapshots the registry so graph-scoped SubWorkflowStepExecutor does not mutate the global singleton', function (): void {
    OperationExecutorRegistry::reset();
    $registry = OperationExecutorRegistry::create();

    subWorkflowGraph($registry);

    expect($registry->all())->toBe([]);
});

it('runs a parent -> child workflow synchronously', function (): void {
    $document = subWorkflowDocument();
    OperationExecutorRegistry::reset();
    $result = subWorkflowGraph(OperationExecutorRegistry::create())
        ->execute($document->firstWorkflow(), $document, []);

    expect($result->status)->toBe(ExecutionStatus::Succeeded)
        ->and($result->stepResults)->toHaveKey('call-child')
        ->and($result->stepResults['call-child']->success)->toBeTrue()
        ->and($result->stepResults['call-child']->outputs)->toBe(['order_id' => 'ord_1'])
        ->and($result->outputs)->toBe(['order_id' => 'ord_1'])
        ->and($result->workflowCallStack)->toBe(['parent']);
});
