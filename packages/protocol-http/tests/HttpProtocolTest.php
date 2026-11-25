<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Sources\Sources;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

it('wires executor, resolver, and outputExtractor through HttpProtocol::create()', function (): void {
    $http = HttpProtocol::create();

    expect($http->executor())->toBeInstanceOf(OperationExecutorPluginInterface::class)
        ->and($http->executor(strictValidation: false))->toBeInstanceOf(OperationExecutorPluginInterface::class)
        ->and($http->resolver())->toBeInstanceOf(OperationResolverInterface::class)
        ->and($http->outputExtractor())->toBeInstanceOf(OutputExtractorInterface::class);
});

it('propagates HttpPolicy, SourcePolicy (isolated per source), LoggerInterface, and TracerInterface W3C traceparent end-to-end', function (): void {
    $capturedRequests = [];
    $logs = [];
    $spans = [];

    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<string> $logs */
        public function __construct(public array &$logs) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = (string) $message;
        }
    };

    $tracer = new class($spans) implements TracerInterface
    {
        /** @param list<array{name: string, status: ?bool, ended: bool}> $spans */
        public function __construct(public array &$spans) {}

        public function startSpan(string $name, array $attributes = []): SpanInterface
        {
            $idx = count($this->spans);
            $this->spans[$idx] = ['name' => $name, 'status' => null, 'ended' => false];
            $ref = &$this->spans[$idx];

            return new class($ref) implements SpanInterface
            {
                /** @param array{name: string, status: ?bool, ended: bool} $slot */
                public function __construct(private array &$slot) {}

                public function setAttribute(string $key, bool|float|int|string $value): void {}

                public function addEvent(string $name, array $attributes = []): void {}

                public function recordException(Throwable $exception): void {}

                public function setStatus(bool $ok, ?string $description = null): void
                {
                    $this->slot['status'] = $ok;
                }

                public function end(): void
                {
                    $this->slot['ended'] = true;
                }
            };
        }

        public function injectContext(array &$carrier): void
        {
            $carrier['traceparent'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        }

        public function extractContext(array $carrier): ?array
        {
            return null;
        }
    };

    $client = new class($capturedRequests) implements ClientInterface
    {
        /** @param list<RequestInterface> $captured */
        public function __construct(public array &$captured) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->captured[] = $request;
            if (str_contains((string) $request->getUri(), '/boom')) {
                throw new RuntimeException('network down');
            }

            return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
        }
    };

    $petstoreOpenApi = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Petstore', 'version' => '1.0'],
        'servers' => [['url' => 'https://petstore.default.test']],
        'security' => [['api_key' => []]],
        'components' => [
            'securitySchemes' => [
                'api_key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
        ],
        'paths' => [
            '/pets' => ['get' => ['operationId' => 'listPets', 'responses' => ['200' => ['description' => 'ok']]]],
            '/boom' => ['get' => ['operationId' => 'failOp', 'responses' => ['200' => ['description' => 'ok']]]],
        ],
    ];

    $paymentsOpenApi = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Payments', 'version' => '1.0'],
        'servers' => [['url' => 'https://payments.default.test']],
        'security' => [['api_key' => []]],
        'components' => [
            'securitySchemes' => [
                'api_key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
        ],
        'paths' => [
            '/charges' => ['get' => ['operationId' => 'listCharges', 'responses' => ['200' => ['description' => 'ok']]]],
        ],
    ];

    $sources = Sources::create()
        ->withDocument(new SourceDocument('petstore', SourceType::Openapi, 'https://petstore.test/openapi.json', $petstoreOpenApi))
        ->withDocument(new SourceDocument('payments', SourceType::Openapi, 'https://payments.test/openapi.json', $paymentsOpenApi));

    $http = HttpProtocol::create($client, new HttpFactory(), $sources)
        ->withPolicy(HttpPolicy::default()->withStrictValidation(false))
        ->withLogger($logger)
        ->withTracer($tracer);

    $executor = $http->executor();
    $doc = new ArazzoDocument(
        '1.0.1',
        new Info('Multi', null, null, '1.0'),
        [
            new SourceDescription('petstore', 'https://petstore.test/openapi.json', SourceType::Openapi),
            new SourceDescription('payments', 'https://payments.test/openapi.json', SourceType::Openapi),
        ],
        [],
        new Components([], [], [], []),
        [],
    );

    $petStep = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.petstore.listPets');
    $petCtx = (new WorkflowContext('wf'))->withActiveSourcePolicy(
        SourcePolicy::default()
            ->withServer('https://petstore.staging.test')
            ->withCredential('api_key', Credential::apiKey('petstore-key'))
            ->withHeader('X-Env', 'staging'),
    );
    $outcome1 = $executor->execute($petStep, $petCtx, $doc, 'exec-1');

    $payStep = StepFactory::http('s2', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.payments.listCharges');
    $payCtx = (new WorkflowContext('wf'))->withActiveSourcePolicy(
        SourcePolicy::default()
            ->withServer('https://payments.staging.test')
            ->withCredential('api_key', Credential::apiKey('payments-key')),
    );
    $outcome2 = $executor->execute($payStep, $payCtx, $doc, 'exec-1');

    // Transport failure records error span and warning log, returns synthetic 500
    $failStep = StepFactory::http('s3', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.petstore.failOp');
    $outcome3 = $executor->execute($failStep, $petCtx, $doc, 'exec-1');

    expect($outcome1->statusCode)->toBe(200)
        ->and($outcome2->statusCode)->toBe(200)
        ->and($outcome3->statusCode)->toBe(500)
        ->and((string) $capturedRequests[0]->getUri())->toBe('https://petstore.staging.test/pets')
        ->and($capturedRequests[0]->getHeaderLine('X-API-Key'))->toBe('petstore-key')
        ->and($capturedRequests[0]->getHeaderLine('X-Env'))->toBe('staging')
        ->and($capturedRequests[0]->getHeaderLine('traceparent'))->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
        ->and((string) $capturedRequests[1]->getUri())->toBe('https://payments.staging.test/charges')
        ->and($capturedRequests[1]->getHeaderLine('X-API-Key'))->toBe('payments-key')
        ->and($capturedRequests[1]->hasHeader('X-Env'))->toBeFalse()
        ->and($logs)->toContain('Executed HTTP operation.')
        ->and($logs)->toContain('HTTP operation failed.');

    $httpSpans = array_values(array_filter($spans, static fn (array $s): bool => $s['name'] === 'arazzo.http.request'));
    expect($httpSpans)->toHaveCount(3)
        ->and($httpSpans[0]['status'])->toBeTrue()
        ->and($httpSpans[1]['status'])->toBeTrue()
        ->and($httpSpans[2]['status'])->toBeFalse();
});
