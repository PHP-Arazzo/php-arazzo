<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Protocol;

use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\PendingCorrelationRegistryInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiResolutionException;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiStepException;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\AsyncApiStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\AsyncApiOperationResolver;
use Alama\Arazzo\Sources\Sources;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AsyncApiExecutorMockClient implements HttpClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function sendRequest(RequestInterface $request, ?float $timeoutSeconds = null): ResponseInterface
    {
        $this->lastRequest = $request;

        return new Response(202);
    }
}

class AsyncApiExecutorMockPendingCorrelations implements PendingCorrelationRegistryInterface
{
    /** @var list<array{correlationId: string, executionId: string, stepId: string, channelPath: string}> */
    public array $created = [];

    public function create(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
    {
        $this->created[] = compact('correlationId', 'executionId', 'stepId', 'channelPath');
    }

    public function findByCorrelationId(string $correlationId): ?PendingCorrelation
    {
        return null;
    }

    public function consume(string $correlationId): void {}

    public function existsForExecution(string $executionId): bool
    {
        return false;
    }
}

function asyncApiExecutorDocument(): ArazzoDocument
{
    return new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), [], null, SpecVersion::V1_1);
}

it('supports steps with action send or receive, not steps without an action', function (): void {
    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
    );

    $plainStep = new Step('s1', null, new StepTarget(), new StepFlow(), new StepIo());
    $sendStep = new Step('s2', null, new StepTarget(action: 'send'), new StepFlow(), new StepIo());
    $receiveStep = new Step('s3', null, new StepTarget(action: 'receive'), new StepFlow(), new StepIo());

    expect($executor->supports($plainStep, asyncApiExecutorDocument()))->toBeFalse();
    expect($executor->supports($sendStep, asyncApiExecutorDocument()))->toBeTrue();
    expect($executor->supports($receiveStep, asyncApiExecutorDocument()))->toBeTrue();
});

it('publishes and resolves immediately for action send', function (): void {
    $client = new AsyncApiExecutorMockClient();
    $httpFactory = new HttpFactory();
    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
    );

    $step = new Step(
        'publish-ride',
        null,
        StepTarget::async('send', 'https://broker.local/publish/rides'),
        new StepFlow(),
        new StepIo(),
    );
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, asyncApiExecutorDocument(), 'exec_1');

    expect($outcome->suspended)->toBeFalse();
    expect($outcome->statusCode)->toBe(202);
    expect($client->lastRequest)->not->toBeNull()
        ->and($client->lastRequest->getMethod())->toBe('POST')
        ->and((string) $client->lastRequest->getUri())->toBe('https://broker.local/publish/rides');
});

it('compiles parameters and requestBody replacements into the message', function (): void {
    $client = new AsyncApiExecutorMockClient();
    $httpFactory = new HttpFactory();
    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
    );

    $step = new Step(
        'publish-order',
        null,
        StepTarget::async('send', 'https://broker.local/publish/orders?apiVersion=2'),
        new StepFlow(),
        new StepIo(
            parameters: [
                new Parameter('source', ParameterIn::Query, new Expression('{$inputs.origin}')),
                new Parameter('X-Trace', ParameterIn::Header, 'trace-123'),
            ],
            requestBody: new RequestBody(
                'application/json',
                ['orderId' => 'old'],
                [new PayloadReplacement('/orderId', new Expression('{$inputs.orderId}'))],
            ),
        ),
    );
    $context = new WorkflowContext('def_1', ['origin' => 'web', 'orderId' => 'ord_42'], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, asyncApiExecutorDocument(), 'exec_1');

    expect($outcome->statusCode)->toBe(202);
    expect($client->lastRequest)->not->toBeNull();

    $uri = $client->lastRequest->getUri();
    expect((string) $uri)->toContain('https://broker.local/publish/orders')
        ->and((string) $uri)->toContain('apiVersion=2')
        ->and((string) $uri)->toContain('source=web');

    $body = json_decode($client->lastRequest->getBody()->getContents(), true);
    expect($body)->toBe(['orderId' => 'ord_42'])
        ->and($client->lastRequest->getHeaderLine('X-Trace'))->toBe('trace-123');
});

it('writes a PendingCorrelation and suspends for action receive', function (): void {
    $pendingCorrelations = new AsyncApiExecutorMockPendingCorrelations();
    $executor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
    );

    $step = new Step(
        'wait-for-ride',
        null,
        StepTarget::async('receive', 'channels/rides/created', new Expression('{$inputs.correlationId}')),
        new StepFlow(),
        new StepIo(),
    );
    $context = new WorkflowContext('def_1', ['correlationId' => 'corr_abc'], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, asyncApiExecutorDocument(), 'exec_1');

    expect($outcome->suspended)->toBeTrue();
    expect($pendingCorrelations->created)->toHaveCount(1);
    expect($pendingCorrelations->created[0]['correlationId'])->toBe('corr_abc');
    expect($pendingCorrelations->created[0]['executionId'])->toBe('exec_1');
    expect($pendingCorrelations->created[0]['stepId'])->toBe('wait-for-ride');
    expect($pendingCorrelations->created[0]['channelPath'])->toBe('channels/rides/created');
});

it('throws when a receive step has no correlationId expression', function (): void {
    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
    );

    $step = new Step('wait', null, StepTarget::async('receive', 'channels/x'), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    expect(fn () => $executor->execute($step, $context, asyncApiExecutorDocument(), 'exec_1'))
        ->toThrow(\LogicException::class);
});

it('dereferences channelPath against AsyncAPI source document on send', function (): void {
    $sourceDesc = new SourceDescription('eventsBroker', 'https://specs.test/asyncapi.json', SourceType::Asyncapi);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [$sourceDesc], [], new Components([], [], [], []), [], null, SpecVersion::V1_1);

    $sourceDoc = new SourceDocument(
        'eventsBroker',
        SourceType::Asyncapi,
        'https://specs.test/asyncapi.json',
        [
            'asyncapi' => '3.0.0',
            'servers' => [
                'production' => [
                    'host' => 'api.events.test:443',
                    'pathname' => '/hub',
                    'protocol' => 'https',
                ],
            ],
            'channels' => [
                'rideEvents' => [
                    'address' => 'rides/requested',
                ],
            ],
        ],
    );

    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $client = new AsyncApiExecutorMockClient();
    $httpFactory = new HttpFactory();

    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $resolver,
    );

    $step = new Step(
        'request-ride',
        null,
        StepTarget::async('send', '{$sourceDescriptions.eventsBroker.url}#/channels/rideEvents'),
        new StepFlow(),
        new StepIo(),
    );
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, $doc, 'exec_1');

    expect($outcome->suspended)->toBeFalse()
        ->and($outcome->statusCode)->toBe(202)
        ->and((string) $client->lastRequest->getUri())->toBe('https://api.events.test/hub/rides/requested');
});

it('dereferences channelPath against AsyncAPI source document on receive and stores concrete target', function (): void {
    $sourceDesc = new SourceDescription('eventsBroker', 'https://specs.test/asyncapi.json', SourceType::Asyncapi);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [$sourceDesc], [], new Components([], [], [], []), [], null, SpecVersion::V1_1);

    $sourceDoc = new SourceDocument(
        'eventsBroker',
        SourceType::Asyncapi,
        'https://specs.test/asyncapi.json',
        [
            'asyncapi' => '3.0.0',
            'servers' => [
                'production' => [
                    'host' => 'api.events.test:443',
                    'pathname' => '/hub',
                    'protocol' => 'https',
                ],
            ],
            'channels' => [
                'rideConfirmations' => [
                    'address' => 'rides/confirmed',
                ],
            ],
        ],
    );

    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $pendingCorrelations = new AsyncApiExecutorMockPendingCorrelations();

    $executor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
        null,
        null,
        null,
        $resolver,
    );

    $step = new Step(
        'wait-for-confirmation',
        null,
        StepTarget::async('receive', '{$sourceDescriptions.eventsBroker.url}#/channels/rideConfirmations', new Expression('{$inputs.correlationId}')),
        new StepFlow(),
        new StepIo(),
    );
    $context = new WorkflowContext('def_1', ['correlationId' => 'ride-999'], [], [], 'wf_1', 'exec_1');

    $outcome = $executor->execute($step, $context, $doc, 'exec_1');

    expect($outcome->suspended)->toBeTrue()
        ->and($pendingCorrelations->created)->toHaveCount(1)
        ->and($pendingCorrelations->created[0]['correlationId'])->toBe('ride-999')
        ->and($pendingCorrelations->created[0]['channelPath'])->toBe('https://api.events.test:443/hub/rides/confirmed');
});

it('throws AsyncApiResolutionException when channelPath names a missing channel in AsyncAPI document', function (): void {
    $sourceDesc = new SourceDescription('eventsBroker', 'https://specs.test/asyncapi.json', SourceType::Asyncapi);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [$sourceDesc], [], new Components([], [], [], []), [], null, SpecVersion::V1_1);

    $sourceDoc = new SourceDocument(
        'eventsBroker',
        SourceType::Asyncapi,
        'https://specs.test/asyncapi.json',
        [
            'asyncapi' => '3.0.0',
            'channels' => [
                'existingChannel' => ['address' => 'existing'],
            ],
        ],
    );

    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $httpFactory = new HttpFactory();

    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $resolver,
    );

    $step = new Step(
        'bad-step',
        null,
        StepTarget::async('send', '{$sourceDescriptions.eventsBroker.url}#/channels/nonExistent'),
        new StepFlow(),
        new StepIo(),
    );
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    expect(fn () => $executor->execute($step, $context, $doc, 'exec_1'))
        ->toThrow(AsyncApiResolutionException::class);
});

it('rejects Arazzo 1.0 documents with unsupportedSpecVersion', function (): void {
    $executor = new AsyncApiStepExecutor(
        new AsyncApiExecutorMockPendingCorrelations(),
        new EvaluationEngine(expression: new ExpressionEngine()),
        new AsyncApiExecutorMockClient(),
    );

    $v1Doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), [], null, SpecVersion::V1_0);
    $step = new Step('s1', null, StepTarget::async('send', 'https://example.com'), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def_1', [], [], [], 'wf_1', 'exec_1');

    expect(fn () => $executor->execute($step, $context, $v1Doc, 'exec_1'))
        ->toThrow(AsyncApiStepException::class);
});

it('handles resolver fallback, unresolvable target URLs, and missing channelPaths', function (): void {
    $client = new AsyncApiExecutorMockClient();
    $httpFactory = new HttpFactory();
    $pendingCorrelations = new AsyncApiExecutorMockPendingCorrelations();

    $throwingResolver = new class(Sources::create()) extends AsyncApiOperationResolver
    {
        public function supportsStep(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
        {
            throw new \RuntimeException('Resolver failure');
        }

        public function resolveTargetUrl(Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
        {
            throw new \RuntimeException('Target resolution failure');
        }
    };

    $executor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $throwingResolver,
    );

    $doc = asyncApiExecutorDocument();
    $context = new WorkflowContext('def_1', ['id' => '123'], [], [], 'wf_1', 'exec_1');

    // 1. Send with direct URL falls back when resolver throws generic exception
    $directSendStep = new Step('direct-send', null, StepTarget::async('send', 'https://direct.example.com/events'), new StepFlow(), new StepIo());
    $outcome = $executor->execute($directSendStep, $context, $doc, 'exec_1');
    expect($outcome->suspended)->toBeFalse()
        ->and($outcome->statusCode)->toBe(202)
        ->and((string) $client->lastRequest->getUri())->toBe('https://direct.example.com/events');

    // 2. Send with non-URL channelPath throws unresolvableChannelTarget when resolver fails
    $nonUrlSendStep = new Step('bad-send', null, StepTarget::async('send', 'customBrokerChannel'), new StepFlow(), new StepIo());
    expect(fn () => $executor->execute($nonUrlSendStep, $context, $doc, 'exec_1'))
        ->toThrow(ExecutionException::class);

    // 3. Send with missing channelPath throws missingSendChannelPath
    $nullSendStep = new Step('null-send', null, new StepTarget(action: 'send'), new StepFlow(), new StepIo());
    expect(fn () => $executor->execute($nullSendStep, $context, $doc, 'exec_1'))
        ->toThrow(AsyncApiStepException::class);

    // 4. Send where resolver returns non-http target URL throws unresolvableChannelTarget
    $relativeResolver = new class(Sources::create()) extends AsyncApiOperationResolver
    {
        public function supportsStep(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
        {
            return new ResolvedOperation(
                new SourceDescription('src', 'https://example.com', SourceType::Asyncapi),
                new NormalizedOpenApiOperation(
                    path: 'orders/created',
                    method: 'POST',
                    resolvedServerUrl: null,
                    pathParameters: [],
                    queryParameters: [],
                    headerParameters: [],
                    cookieParameters: [],
                    requestBodies: [],
                    responses: [],
                ),
                'op',
            );
        }

        public function resolveTargetUrl(Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
        {
            return 'orders/created';
        }
    };
    $relativeExecutor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $relativeResolver,
    );
    expect(fn () => $relativeExecutor->execute($nonUrlSendStep, $context, $doc, 'exec_1'))
        ->toThrow(ExecutionException::class);

    // 5. Receive step falls back to raw channelPath when resolver throws generic exception
    $receiveStep = new Step(
        'recv-step',
        null,
        StepTarget::async('receive', 'rawChannelAddress', new Expression('$inputs.id')),
        new StepFlow(),
        new StepIo(),
    );
    $receiveOutcome = $executor->execute($receiveStep, $context, $doc, 'exec_1');
    expect($receiveOutcome->suspended)->toBeTrue()
        ->and($pendingCorrelations->created[count($pendingCorrelations->created) - 1]['channelPath'])->toBe('rawChannelAddress');

    // 6. Receive step rethrows AsyncApiResolutionException when resolver throws it
    $resolutionExceptionResolver = new class(Sources::create()) extends AsyncApiOperationResolver
    {
        public function supportsStep(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
        {
            throw AsyncApiResolutionException::channelNotFound('badCh', 'src');
        }

        public function resolveTargetUrl(Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
        {
            throw AsyncApiResolutionException::channelNotFound('badCh', 'src');
        }
    };
    $throwingResolutionExecutor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $resolutionExceptionResolver,
    );
    expect(fn () => $throwingResolutionExecutor->execute($receiveStep, $context, $doc, 'exec_1'))
        ->toThrow(AsyncApiResolutionException::class);
});
