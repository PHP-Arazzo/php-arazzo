<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Protocol;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Exceptions\TransportException;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\RequestValidatorInterface;
use Alama\Arazzo\Contracts\Interfaces\ResponseValidatorInterface;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Evaluation\Data\CriterionResult;
use Alama\Arazzo\Evaluation\Data\CriterionResultCollection;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;
use Alama\Arazzo\Protocol\Http\Exceptions\RateLimitExceededException;
use Alama\Arazzo\Protocol\Http\Execution\Interfaces\OpenApiExecutorInterface;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Psr\Http\Message\ResponseInterface;

class HttpStepExecutorMockEngine implements EvaluationEngineInterface
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

class HttpStepExecutorMockOpenApiExecutor implements OpenApiExecutorInterface
{
    public function __construct(private ResponseInterface $response) {}

    public function execute(
        OpenApiOperationHandle $resolvedOperation,
        OpenApiPayload $payload,
        ?callable $requestInterceptor = null,
        ?float $timeoutSeconds = null,
        ?SourcePolicy $sourcePolicy = null,
    ): ResponseInterface {
        if ($requestInterceptor) {
            $requestInterceptor(new Request('GET', 'http://localhost/thing'));
        }

        return $this->response;
    }
}

function createMockDocumentResolver(): OpenApiOperationResolver
{
    $mock = Mockery::mock(OpenApiOperationResolver::class);
    $mock->shouldReceive('resolve')->andReturn(new OpenApiOperationHandle(
        new ResolvedOperation(
            source: new SourceDescription('test-src', 'http://example.com/openapi.json', SourceType::Openapi),
            normalized: new NormalizedOpenApiOperation(path: '/rides', method: 'get'),
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

function httpStepExecutorDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        '1.0.0',
        new Info('T', null, null, '1'),
        [new SourceDescription('test', 'test.json', SourceType::Openapi)],
        [],
        new Components([], [], [], []),
        [],
    );
}

it('supports a step with no action set', function (): void {
    $executor = new HttpStepExecutor(
        new HttpStepExecutorMockOpenApiExecutor(new Response(200)),
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        Mockery::mock(OutputExtractorInterface::class),
        Mockery::mock(ResponseValidatorInterface::class),
    );
    $step = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());

    expect($executor->supports($step, httpStepExecutorDocument()))->toBeTrue();
});

it('does not support a step with an action set', function (): void {
    $executor = new HttpStepExecutor(
        new HttpStepExecutorMockOpenApiExecutor(new Response(200)),
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        Mockery::mock(OutputExtractorInterface::class),
        Mockery::mock(ResponseValidatorInterface::class),
    );
    $step = new Step('s1', null, new StepTarget(action: 'send'), new StepFlow(), new StepIo());

    expect($executor->supports($step, httpStepExecutorDocument()))->toBeFalse();
});

it('executes the request and returns a resolved outcome with statusCode/outputs/body', function (): void {
    $response = new Response(201, [], json_encode(['id' => 42]));
    $openApiExecutor = new HttpStepExecutorMockOpenApiExecutor($response);
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturnUsing(function (Step $step, WorkflowContextInterface $context): array {
        return ['echoedBody' => $context->getSteps()[$step->stepId]['response']['body'] ?? null];
    });
    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        Mockery::mock(ResponseValidatorInterface::class),
        strictDefault: false,
    );

    $step = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, httpStepExecutorDocument(), 'exec_1');

    expect($outcome->suspended)->toBeFalse();
    expect($outcome->statusCode)->toBe(201);
    expect($outcome->responseBody)->toBe(['id' => 42]);
    expect($outcome->outputs)->toBe(['echoedBody' => ['id' => 42]]);
});

it('stores the response on the context before calling extractOutputs, fixing the stale-context ordering bug', function (): void {
    $response = new Response(200, [], json_encode(['x' => 1]));
    $openApiExecutor = new HttpStepExecutorMockOpenApiExecutor($response);
    $lastContext = null;
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturnUsing(function (Step $step, WorkflowContextInterface $context) use (&$lastContext): array {
        $lastContext = $context;

        return [];
    });
    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        Mockery::mock(ResponseValidatorInterface::class),
        strictDefault: false,
    );

    $step = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1');

    $executor->execute($step, $context, httpStepExecutorDocument(), 'exec_1');

    expect($lastContext->getSteps()['s1']['response']['body'])->toBe(['x' => 1]);
});

it('validates response schema and fails fast on failure', function (): void {
    $validator = Mockery::mock(ResponseValidatorInterface::class);
    $validator->shouldReceive('validateResponseSchema')->once()->andThrow(
        new SchemaValidationException('sync-step', [['path' => '/', 'message' => 'bad schema']]),
    );
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->never();

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor) {
        if ($interceptor) {
            $interceptor(new Request('GET', '/'));
        }

        return new Response(200, [], '{"bad": true}');
    });

    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        $outputExtractor,
        $validator,
        true, // strict default
    );
    $step = StepFactory::http(
        stepId: 'sync-step',
        description: null,
        flow: new StepFlow(strictValidation: true),
        io: new StepIo(),
        operationId: 'op',
    );

    $document = httpStepExecutorDocument();

    try {
        $executor->execute($step, new WorkflowContext('wf_1'), $document, 'exec_1');
        $this->fail('Expected exception');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('sync-step');
    }
});

it('validates response schema by default when the step does not opt out', function (): void {
    $validator = Mockery::mock(ResponseValidatorInterface::class);
    $validator->shouldReceive('validateResponseSchema')->once()->andThrow(
        new SchemaValidationException('default-step', [['path' => '/', 'message' => 'bad schema']]),
    );
    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->never();

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor) {
        if ($interceptor) {
            $interceptor(new Request('GET', '/'));
        }

        return new Response(200, [], '{"bad": true}');
    });

    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        $outputExtractor,
        $validator,
    );
    $step = StepFactory::http(
        stepId: 'default-step',
        description: null,
        flow: new StepFlow(),
        io: new StepIo(),
        operationId: 'op',
    );

    try {
        $executor->execute($step, new WorkflowContext('wf_1'), httpStepExecutorDocument(), 'exec_1');
        $this->fail('Expected exception');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('default-step');
    }
});

it('forwards active SourcePolicy timeout and strictValidation override to executor and schema validator', function (): void {
    $capturedTimeout = null;
    $capturedPolicy = null;

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->andReturnUsing(function ($resolved, $payload, $interceptor, $timeout, $policy) use (&$capturedTimeout, &$capturedPolicy) {
        $capturedTimeout = $timeout;
        $capturedPolicy = $policy;
        if ($interceptor) {
            $interceptor(new Request('GET', '/'));
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
    });

    $validator = Mockery::mock(ResponseValidatorInterface::class);
    $validator->shouldReceive('validateResponseSchema')->never();

    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturn([]);

    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        $validator,
        strictDefault: true,
    );

    $sourcePolicy = SourcePolicy::default()
        ->withTimeoutSeconds(9.5)
        ->withStrictValidation(false);

    $context = (new WorkflowContext('wf_1'))->withActiveSourcePolicy($sourcePolicy);
    $step = StepFactory::http('policy-step', null, new StepFlow(), new StepIo(), operationId: 'op');

    $outcome = $executor->execute($step, $context, httpStepExecutorDocument(), 'exec_1');

    expect($outcome->statusCode)->toBe(200)
        ->and($capturedTimeout)->toBe(9.5)
        ->and($capturedPolicy)->toBe($sourcePolicy)
        ->and($executor->name())->toBe('http')
        ->and($executor->priority())->toBe(HttpStepExecutor::PLUGIN_PRIORITY);

    // Step-level timeout (in ms) overrides SourcePolicy timeout and IdempotencyKeyInjector runs when configured
    $injectedExecutor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        $validator,
        strictDefault: false,
        injector: new IdempotencyKeyInjector(true, 'Idempotency-Key'),
    );
    $stepWithTimeout = StepFactory::http('t-step', null, new StepFlow(timeout: 2500), new StepIo(), operationId: 'op');
    $injectedExecutor->execute($stepWithTimeout, $context, httpStepExecutorDocument(), 'exec_1');

    expect($capturedTimeout)->toBe(2.5);
});

it('validates outbound request schema and fails fast without calling transport when strict', function (): void {
    $requestValidator = Mockery::mock(RequestValidatorInterface::class);
    $requestValidator->shouldReceive('validateRequest')->once()->andThrow(
        SchemaValidationException::forRequestStep('req-fail-step', [['path' => '/body/name', 'message' => "expected type 'string', got int"]]),
    );

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->never(); // Zero transport calls!

    $responseValidator = Mockery::mock(ResponseValidatorInterface::class);
    $responseValidator->shouldReceive('validateResponseSchema')->never();

    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->never();

    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        $responseValidator,
        strictDefault: true,
        requestValidator: $requestValidator,
    );

    $step = StepFactory::http('req-fail-step', null, new StepFlow(strictValidation: true), new StepIo(), operationId: 'op');

    try {
        $executor->execute($step, new WorkflowContext('wf_1'), httpStepExecutorDocument(), 'exec_1');
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('req-fail-step')
            ->and($e->getMessage())->toContain("Request schema validation failed for step 'req-fail-step'")
            ->and($e->violations[0]['path'])->toBe('/body/name');
    }
});

it('skips outbound request validation when strictValidation is false (lenient mode)', function (): void {
    $requestValidator = Mockery::mock(RequestValidatorInterface::class);
    $requestValidator->shouldReceive('validateRequest')->never();

    $openApiExecutor = Mockery::mock(OpenApiExecutorInterface::class);
    $openApiExecutor->shouldReceive('execute')->once()->andReturn(new Response(200, [], '{}'));

    $responseValidator = Mockery::mock(ResponseValidatorInterface::class);
    $responseValidator->shouldReceive('validateResponseSchema')->never();

    $outputExtractor = Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturn([]);

    $executor = new HttpStepExecutor(
        $openApiExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        $outputExtractor,
        $responseValidator,
        strictDefault: true,
        requestValidator: $requestValidator,
    );

    $step = StepFactory::http('lenient-step', null, new StepFlow(strictValidation: false), new StepIo(), operationId: 'op');

    $outcome = $executor->execute($step, new WorkflowContext('wf_1'), httpStepExecutorDocument(), 'exec_1');

    expect($outcome->statusCode)->toBe(200);
});

it('returns a typed failed outcome with null statusCode when OpenApiExecutor throws RateLimitExceededException', function (): void {
    $throwingExecutor = new class() implements OpenApiExecutorInterface
    {
        public function execute(
            OpenApiOperationHandle $operation,
            OpenApiPayload $payload,
            ?callable $requestInterceptor = null,
            ?float $timeoutSeconds = null,
            ?SourcePolicy $sourcePolicy = null,
        ): ResponseInterface {
            throw RateLimitExceededException::rateLimited('api.example.com', 10.0);
        }
    };

    $executor = new HttpStepExecutor(
        $throwingExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        Mockery::mock(OutputExtractorInterface::class),
        Mockery::mock(ResponseValidatorInterface::class),
        strictDefault: false,
    );

    $step = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, httpStepExecutorDocument(), 'exec_1');

    expect($outcome->statusCode)->toBeNull()
        ->and($outcome->isFailed())->toBeTrue()
        ->and($outcome->failureCategory)->toBe('transport')
        ->and($outcome->exception)->toBeInstanceOf(TransportException::class)
        ->and($outcome->responseBody['error'])->toContain('rate-limited (10 req/s exceeded)');
});

it('returns a typed failed outcome with null statusCode on network or connection errors', function (): void {
    $failingExecutor = new class() implements OpenApiExecutorInterface
    {
        public function execute(
            OpenApiOperationHandle $operation,
            OpenApiPayload $payload,
            ?callable $requestInterceptor = null,
            ?float $timeoutSeconds = null,
            ?SourcePolicy $sourcePolicy = null,
        ): ResponseInterface {
            if ($requestInterceptor !== null) {
                $requestInterceptor(new Request('GET', 'https://api.example.com/status'));
            }
            throw new \RuntimeException('Failed to connect to api.example.com port 443: Connection refused');
        }
    };

    $executor = new HttpStepExecutor(
        $failingExecutor,
        createMockDocumentResolver(),
        new HttpStepExecutorMockEngine(),
        Mockery::mock(OutputExtractorInterface::class),
        Mockery::mock(ResponseValidatorInterface::class),
        strictDefault: false,
    );

    $step = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, httpStepExecutorDocument(), 'exec_1');

    expect($outcome->statusCode)->toBeNull()
        ->and($outcome->isFailed())->toBeTrue()
        ->and($outcome->failureCategory)->toBe('transport')
        ->and($outcome->exception)->toBeInstanceOf(TransportException::class)
        ->and($outcome->request['url'] ?? null)->toBe('https://api.example.com/status');
});
