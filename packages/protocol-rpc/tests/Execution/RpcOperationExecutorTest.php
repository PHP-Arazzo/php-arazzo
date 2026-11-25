<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Rpc\Execution;

use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\NullSpan;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\RpcProtocol;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\Data\CriterionResult;
use Alama\Arazzo\Evaluation\Data\CriterionResultCollection;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;
use Alama\Arazzo\Protocol\Rpc\Execution\RpcFailureMapper;
use Alama\Arazzo\Protocol\Rpc\Execution\RpcRequestBuilder;
use Alama\Arazzo\Protocol\Rpc\Plugins\OperationExecutor\RpcOperationExecutor;
use Alama\Arazzo\Protocol\Rpc\Protocol\RpcMessageCodec;
use Alama\Arazzo\Protocol\Rpc\Transfer\RpcResponseTransfer;
use Alama\Arazzo\Protocol\Rpc\Transfer\RpcResponseTransferMapper;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

class RpcExecutorMockEngine implements EvaluationEngineInterface
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
        if ($value instanceof Expression) {
            return $this->evaluate($value, new EvaluationContext($context, $stepId));
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

class RpcExecutorFakeResolver implements OperationResolverInterface
{
    public function __construct(private ResolvedOperation $resolved) {}

    public function supportsStep(Step $step, ArazzoDocument $document): bool
    {
        return true;
    }

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        return $this->resolved;
    }
}

class RpcExecutorFakeClient implements HttpClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public bool $throwOnSend = false;

    public function __construct(private ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request, ?float $timeoutSeconds = null): ResponseInterface
    {
        if ($this->throwOnSend) {
            throw new RpcExecutorClientException('connection refused');
        }

        $this->lastRequest = $request;

        return $this->response;
    }
}

class RpcExecutorClientException extends RuntimeException implements ClientExceptionInterface {}

/**
 * @return list<array{name: string, type: string, number: int, repeated: bool}>
 */
function rpcExecutorResultFields(): array
{
    return [['name' => 'result', 'type' => 'int32', 'number' => 1, 'repeated' => false]];
}

/**
 * @return list<array{name: string, type: string, number: int, repeated: bool}>
 */
function rpcExecutorAddFields(): array
{
    return [
        ['name' => 'a', 'type' => 'int32', 'number' => 1, 'repeated' => false],
        ['name' => 'b', 'type' => 'int32', 'number' => 2, 'repeated' => false],
    ];
}

function rpcExecutorBody(int $result, bool $framed = true): string
{
    $codec = new RpcMessageCodec();
    $message = $codec->encodeBinary(rpcExecutorResultFields(), ['result' => $result]);

    return $framed ? $codec->frame($message) : $message;
}

function rpcExecutorDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        '1.0.0',
        new Info('T', null, null, '1'),
        [new SourceDescription('calculator', 'calculator.proto', SourceType::Protobuf)],
        [],
        new Components([], [], [], []),
        [],
    );
}

function rpcExecutorResolvedOperation(
    RpcProtocol $protocol = RpcProtocol::Grpc,
    string $url = 'http://localhost:9090',
    bool $withResultFields = true,
): ResolvedOperation {
    return new ResolvedOperation(
        source: new SourceDescription('calculator', 'calculator.proto', SourceType::Protobuf),
        normalized: new NormalizedOpenApiOperation(
            path: 'CalculatorService/Add',
            method: 'POST',
            resolvedServerUrl: $url,
            pathParameters: [],
            queryParameters: [],
            headerParameters: [],
            cookieParameters: [],
            requestBodies: ['application/protobuf' => [
                'requestFields' => rpcExecutorAddFields(),
                'responseFields' => $withResultFields ? rpcExecutorResultFields() : [],
            ]],
            responses: [],
        ),
        rpcProtocol: $protocol,
    );
}

function rpcExecutor(OperationResolverInterface $resolver, HttpClientInterface $client): RpcOperationExecutor
{
    $psr17 = new HttpFactory();

    return new RpcOperationExecutor(
        operationResolver: $resolver,
        requestBuilder: new RpcRequestBuilder(
            requestFactory: $psr17,
            streamFactory: $psr17,
            evaluationEngine: new RpcExecutorMockEngine(),
            codec: new RpcMessageCodec(),
        ),
        httpClient: $client,
        responseMapper: new RpcResponseTransferMapper(new RpcMessageCodec()),
        failureMapper: new RpcFailureMapper(),
    );
}

function rpcExecutorStep(array $parameters = []): Step
{
    return new Step('add', null, StepTarget::rpc('$sourceDescriptions.calculator.CalculatorService/Add', RpcProtocol::Grpc), new StepFlow(), new StepIo(parameters: $parameters));
}

function rpcExecutorContext(): WorkflowContext
{
    return new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');
}

it('exposes the rpc plugin contract', function (): void {
    $executor = rpcExecutor(
        new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()),
        new RpcExecutorFakeClient(new Response(200)),
    );

    expect($executor)->toBeInstanceOf(OperationExecutorPluginInterface::class);
    expect($executor->name())->toBe('rpc');
    expect($executor->priority())->toBe(50);
    expect($executor->supports(rpcExecutorStep(), rpcExecutorDocument()))->toBeTrue();
    expect($executor->supports(new Step('a', null, new StepTarget(action: 'send'), new StepFlow(), new StepIo()), rpcExecutorDocument()))->toBeFalse();
});

it('posts a framed grpc message and reports the decoded transfer on success', function (): void {
    $client = new RpcExecutorFakeClient(new Response(200, ['Grpc-Status' => '0', 'Content-Type' => 'application/grpc'], rpcExecutorBody(8)));
    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()), $client);

    $outcome = $executor->execute(
        rpcExecutorStep([new Parameter('a', null, 5), new Parameter('b', null, 3)]),
        rpcExecutorContext(),
        rpcExecutorDocument(),
        'exec-1',
    );

    expect($client->lastRequest)->not->toBeNull();
    expect($client->lastRequest->getMethod())->toBe('POST');
    expect((string) $client->lastRequest->getUri())->toBe('http://localhost:9090');
    expect($client->lastRequest->getHeaderLine('Content-Type'))->toBe('application/grpc');

    $codec = new RpcMessageCodec();
    $framedRequest = (string) $client->lastRequest->getBody();
    expect($codec->unframe($framedRequest))->toBe($codec->encodeBinary(rpcExecutorAddFields(), ['a' => 5, 'b' => 3]));

    expect($outcome->suspended)->toBeFalse();
    expect($outcome->failureCategory)->toBeNull();
    expect($outcome->statusCode)->toBe(0);
    expect($outcome->responseBody['transfer'])->toBeInstanceOf(RpcResponseTransfer::class);
    expect($outcome->responseBody['transfer']->view('proto'))->toBe(['result' => 8]);
});

it('maps a twirp success to the HTTP status and a decoded proto view', function (): void {
    $client = new RpcExecutorFakeClient(new Response(200, ['Content-Type' => 'application/protobuf'], rpcExecutorBody(8, false)));
    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation(RpcProtocol::Twirp)), $client);

    $outcome = $executor->execute(
        rpcExecutorStep([new Parameter('a', null, 5), new Parameter('b', null, 3)]),
        rpcExecutorContext(),
        rpcExecutorDocument(),
        'exec-1',
    );

    expect($client->lastRequest->getHeaderLine('Content-Type'))->toBe('application/protobuf');
    expect((string) $client->lastRequest->getBody())->toBe("\x08\x05\x10\x03");

    expect($outcome->statusCode)->toBe(200);
    expect($outcome->failureCategory)->toBeNull();
    expect($outcome->responseBody['transfer']->view('proto'))->toBe(['result' => 8]);
});

it('maps a grpc-status error into a failed rpc-error outcome', function (): void {
    $client = new RpcExecutorFakeClient(new Response(200, ['Grpc-Status' => '13', 'Grpc-Message' => 'invalid argument'], "\x00\x00\x00\x00\x00"));
    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()), $client);

    $outcome = $executor->execute(rpcExecutorStep(), rpcExecutorContext(), rpcExecutorDocument(), 'exec-1');

    expect($outcome->failureCategory)->toBe('rpc-error');
    expect($outcome->statusCode)->toBe(13);
    expect($outcome->responseBody['rpc.error'])->toBe(13);
    expect($outcome->responseBody['rpc.message'])->toBe('invalid argument');
    expect($outcome->responseBody['transfer'])->toBeInstanceOf(RpcResponseTransfer::class);
});

it('maps a non-2xx response without an RPC code into a transport failure', function (): void {
    $client = new RpcExecutorFakeClient(new Response(503, [], ''));
    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()), $client);

    $outcome = $executor->execute(rpcExecutorStep(), rpcExecutorContext(), rpcExecutorDocument(), 'exec-1');

    expect($outcome->failureCategory)->toBe('transport');
    expect($outcome->statusCode)->toBe(503);
});

it('maps a transport exception into a transport failure with the message in meta', function (): void {
    $client = new RpcExecutorFakeClient(new Response(200));
    $client->throwOnSend = true;
    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()), $client);

    $outcome = $executor->execute(rpcExecutorStep(), rpcExecutorContext(), rpcExecutorDocument(), 'exec-1');

    expect($outcome->failureCategory)->toBe('transport');
    expect($outcome->responseBody['transfer']->meta()['error'])->toBe('connection refused');
});

it('applies SourcePolicy headers/timeout and emits Tier-2 logs and W3C traceparent spans', function (): void {
    $client = new RpcExecutorFakeClient(new Response(200, ['Grpc-Status' => '0', 'Content-Type' => 'application/grpc'], rpcExecutorBody(8)));

    $logs = [];
    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<string> $logs */
        public function __construct(public array &$logs) {}

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->logs[] = (string) $message;
        }
    };

    $spans = [];
    $tracer = new class($spans) implements TracerInterface
    {
        /** @param list<string> $spans */
        public function __construct(public array &$spans) {}

        public function startSpan(string $name, array $attributes = []): SpanInterface
        {
            $this->spans[] = $name;

            return new NullSpan();
        }

        public function injectContext(array &$carrier): void
        {
            $carrier['traceparent'] = '00-33333333333333333333333333333333-4444444444444444-01';
        }

        public function extractContext(array $carrier): ?array
        {
            return null;
        }
    };

    $executor = rpcExecutor(new RpcExecutorFakeResolver(rpcExecutorResolvedOperation()), $client)
        ->withLogger($logger)
        ->withTracer($tracer);

    $context = rpcExecutorContext()->withActiveSourcePolicy(
        SourcePolicy::default()
            ->withHeader('X-Rpc-Tenant', 'beta')
            ->withHeader('Content-Type', 'should-not-override')
            ->withTimeoutSeconds(4.0),
    );

    $outcome = $executor->execute(rpcExecutorStep([new Parameter('a', null, 5), new Parameter('b', null, 3)]), $context, rpcExecutorDocument(), 'exec-1');

    expect($outcome->statusCode)->toBe(0)
        ->and($client->lastRequest->getHeaderLine('X-Rpc-Tenant'))->toBe('beta')
        ->and($client->lastRequest->getHeaderLine('Content-Type'))->toBe('application/grpc')
        ->and($client->lastRequest->getHeaderLine('traceparent'))->toBe('00-33333333333333333333333333333333-4444444444444444-01')
        ->and($spans)->toBe(['arazzo.rpc.request'])
        ->and($logs)->toContain('Executed RPC operation.');
});

it('throws ExecutionException when RPC resolution fails or rpcProtocol is missing', function (): void {
    $failingResolver = new class() implements OperationResolverInterface
    {
        public function supportsStep(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
        {
            throw new RuntimeException('proto missing');
        }
    };

    $executor = rpcExecutor($failingResolver, new RpcExecutorFakeClient(new Response(200)));
    expect(fn () => $executor->execute(rpcExecutorStep(), rpcExecutorContext(), rpcExecutorDocument(), 'exec-1'))
        ->toThrow(ExecutionException::class, 'RPC resolution failed');

    $nullProtocolOp = new ResolvedOperation(
        source: new SourceDescription('calculator', 'calculator.proto', SourceType::Protobuf),
        normalized: new NormalizedOpenApiOperation(
            path: '/calculator.CalculatorService/Add',
            method: 'post',
            resolvedServerUrl: 'http://localhost:9090',
            pathParameters: [],
            queryParameters: [],
            headerParameters: [],
            cookieParameters: [],
            requestBodies: ['application/protobuf' => ['requestFields' => [], 'responseFields' => []]],
            responses: [],
        ),
        rpcProtocol: null,
    );
    $nullProtoExecutor = new RpcOperationExecutor(
        operationResolver: new RpcExecutorFakeResolver($nullProtocolOp),
        requestBuilder: new class(new HttpFactory(), new HttpFactory(), new RpcExecutorMockEngine(), new RpcMessageCodec()) extends RpcRequestBuilder
        {
            public function build(Step $step, WorkflowContext $context, ArazzoDocument $document, ResolvedOperation $resolved): RequestInterface
            {
                return (new HttpFactory())->createRequest('POST', 'http://localhost:9090');
            }
        },
        httpClient: new RpcExecutorFakeClient(new Response(200)),
        responseMapper: new RpcResponseTransferMapper(new RpcMessageCodec()),
        failureMapper: new RpcFailureMapper(),
    );

    expect(fn () => $nullProtoExecutor->execute(rpcExecutorStep(), rpcExecutorContext(), rpcExecutorDocument(), 'exec-1'))
        ->toThrow(ExecutionException::class, 'does not declare an rpcProtocol');
});
