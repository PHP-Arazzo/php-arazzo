<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\ResponseValidatorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\Interfaces\OpenApiExecutorInterface;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function createTestDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, 'desc', '1.0.0'),
        sourceDescriptions: [new SourceDescription('test-src', 'http://example.com/openapi.json', SourceType::Openapi)],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

function createMockDocumentResolver(): OpenApiOperationResolver
{
    $mock = Mockery::mock(OpenApiOperationResolver::class);
    $mock->shouldReceive('resolve')->andReturn(new OpenApiOperationHandle(
        new ResolvedOperation(
            source: new SourceDescription('test-src', 'http://example.com/openapi.json', SourceType::Openapi),
            normalized: new NormalizedOpenApiOperation('/rides', 'get', null, [], [], [], [], [], []),
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        new OpenApi([]),
        new Operation([]),
    ));

    return $mock;
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('throws ExecutionException when no OperationExecutorPluginInterface supports the step', function (): void {
    $executor = new StepExecutor(new EvaluationEngine(expression: new ExpressionEngine()));
    $step = StepFactory::http('orphan-step', null, new StepFlow(), new StepIo(), 'op');

    $executor->execute($step, new WorkflowContext('def-1'), createTestDocument());
})->throws(ExecutionException::class, "No OperationExecutorPluginInterface supports step 'orphan-step'");

it('throws ExecutionException when a plugin returns a suspended outcome in sync mode', function (): void {
    $plugin = new class() implements OperationExecutorPluginInterface
    {
        public function name(): string
        {
            return 'suspending';
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
            return StepExecutionOutcome::suspended();
        }
    };

    $executor = new StepExecutor(new EvaluationEngine(expression: new ExpressionEngine()), localExecutors: [$plugin]);
    $step = StepFactory::http('wait-step', null, new StepFlow(), new StepIo(), 'op');

    $executor->execute($step, new WorkflowContext('def-1'), createTestDocument());
})->throws(ExecutionException::class, "Step 'wait-step' suspended");

it('evaluates declared step outputs when a custom plugin returns only a response body', function (): void {
    $plugin = new class() implements OperationExecutorPluginInterface
    {
        public function name(): string
        {
            return 'body-only';
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
            return StepExecutionOutcome::resolved(200, [], ['id' => 42]);
        }
    };

    $executor = new StepExecutor(new EvaluationEngine(expression: new ExpressionEngine()), localExecutors: [$plugin]);
    $step = StepFactory::http(
        'get-item',
        null,
        new StepFlow(),
        new StepIo(outputs: ['itemId' => new Expression('$response.body#/id')]),
        'op',
    );

    [$context, $success] = $executor->execute($step, new WorkflowContext('def-1'), createTestDocument());

    expect($success)->toBeTrue()
        ->and($context->getSteps()['get-item']['outputs']['itemId'] ?? null)->toBe(42);
});

it('validates response schema through HttpStepExecutor when strict validation is enabled', function (): void {
    $validator = Mockery::mock(ResponseValidatorInterface::class);
    $validator->shouldReceive('validateResponseSchema')->once()->andThrow(
        new SchemaValidationException('test-step', [['path' => '/', 'message' => 'bad schema']]),
    );
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->never();

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor) {
        if ($interceptor) {
            $interceptor(new Request('GET', '/'));
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{"bad": true}');
    });

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $httpPlugin = new HttpStepExecutor($openApiExecutor, createMockDocumentResolver(), $engine, $outputExtractor, $validator);
    $executor = new StepExecutor($engine, localExecutors: [$httpPlugin]);
    $step = StepFactory::http('test-step', null, new StepFlow(strictValidation: true), new StepIo(), 'op');

    try {
        $executor->execute($step, new WorkflowContext('test-def'), createTestDocument());
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('test-step');
    }
});

it('skips response schema validation when configured off globally and locally on HttpStepExecutor', function (): void {
    $validator = Mockery::mock(ResponseValidatorInterface::class);
    $validator->shouldReceive('validateResponseSchema')->never();
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->once()->andReturn([]);

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor) {
        if ($interceptor) {
            $interceptor(new Request('GET', '/'));
        }

        return new Response(200, [], '{"bad": true}');
    });

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $httpPlugin = new HttpStepExecutor($openApiExecutor, createMockDocumentResolver(), $engine, $outputExtractor, $validator, strictDefault: false);
    $executor = new StepExecutor($engine, localExecutors: [$httpPlugin]);
    $step = StepFactory::http('test-step', null, new StepFlow(), new StepIo(), 'op');

    $result = $executor->execute($step, new WorkflowContext('test-def'), createTestDocument());
    expect($result[1])->toBeTrue();
});

it('injects the Idempotency-Key header through HttpStepExecutor when enabled', function (): void {
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturn([]);

    $capturedRequest = null;
    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor) use (&$capturedRequest) {
        $request = new Request('POST', 'https://api.example.com/x', [], '{"a":1}');
        if ($interceptor) {
            $capturedRequest = $interceptor($request);
        }

        return new Response(200, [], '{}');
    });

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $httpPlugin = new HttpStepExecutor(
        openApiExecutor: $openApiExecutor,
        operationResolver: createMockDocumentResolver(),
        evaluationEngine: $engine,
        outputExtractor: $outputExtractor,
        schemaValidator: Mockery::mock(ResponseValidatorInterface::class),
        strictDefault: false,
        injector: new IdempotencyKeyInjector(enabledDefault: true, headerDefault: 'Idempotency-Key'),
    );
    $executor = new StepExecutor($engine, localExecutors: [$httpPlugin]);
    $step = StepFactory::http('test-step', null, new StepFlow(), new StepIo(), 'op');

    $executor->execute($step, new WorkflowContext('def-1', [], [], [], 'wf-1', 'exec-1'), createTestDocument());

    expect($capturedRequest->getHeaderLine('Idempotency-Key'))->toMatch('/^[0-9a-f]{64}$/');
});
