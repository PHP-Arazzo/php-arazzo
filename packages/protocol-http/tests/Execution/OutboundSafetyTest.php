<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Protocol\Http\Exceptions\HttpPolicyViolationException;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\HostResolver;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Protocol\Http\Policy\NetworkPolicy;
use Alama\Arazzo\Protocol\Http\Policy\OutboundSafetyPolicy;
use Alama\Arazzo\Protocol\Http\Policy\RedirectPolicy;
use Alama\Arazzo\Protocol\Http\Policy\TransferPolicy;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use cebe\openapi\Reader;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Mockery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

function safetyOperation(string $serverUrl = 'https://api.example.com', string $sourceName = 'petstore', string $method = 'get', array $extra = []): OpenApiOperationHandle
{
    $spec = array_merge([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Safety Test API', 'version' => '1.0'],
        'servers' => [['url' => $serverUrl]],
        'paths' => [
            '/items' => [
                $method => [
                    'operationId' => 'itemsOp',
                ],
            ],
        ],
    ], $extra);

    $openApi = Reader::readFromJson(json_encode($spec, JSON_THROW_ON_ERROR));
    $source = new SourceDescription($sourceName, 'spec.json', SourceType::Openapi);
    $normalized = new NormalizedOpenApiOperation(
        path: '/items',
        method: $method,
        resolvedServerUrl: $serverUrl,
    );

    return new OpenApiOperationHandle(
        operation: new ResolvedOperation(source: $source, normalized: $normalized),
        openApi: $openApi,
        cebeOperation: clone $openApi->paths->getPath('/items')->{$method},
    );
}

function stubHostResolver(array $map = []): HostResolver
{
    return new class($map) extends HostResolver
    {
        /** @param array<string, list<string>> $map */
        public function __construct(private readonly array $map) {}

        public function resolveIps(string $host): array
        {
            return $this->map[strtolower($host)] ?? [];
        }
    };
}

it('refuses a redirect chain exceeding maxRedirects with transport.redirect_limit (S1)', function (): void {
    $calls = 0;
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->times(3)->andReturnUsing(function () use (&$calls): Response {
        $calls++;

        return new Response(302, ['Location' => "https://api.example.com/hop/{$calls}"]);
    });

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->with('transport.redirect_limit', Mockery::type('array'))->once();
    $logger->shouldReceive('warning')->with('HTTP operation failed.', Mockery::type('array'))->once();

    $span = Mockery::mock(SpanInterface::class);
    $span->shouldReceive('addEvent')->with('transport.redirect_limit', Mockery::type('array'))->once();
    $span->shouldReceive('recordException')->once();
    $span->shouldReceive('setStatus')->with(false, Mockery::type('string'))->once();
    $span->shouldReceive('end')->once();

    $tracer = Mockery::mock(TracerInterface::class);
    $tracer->shouldReceive('injectContext');
    $tracer->shouldReceive('startSpan')->andReturn($span);

    $policy = HttpPolicy::default()->withSafety(
        OutboundSafetyPolicy::default()->withRedirects(RedirectPolicy::default()->withMaxRedirects(2)),
    );

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory(), $logger, $tracer, $policy))
        ->withHostResolver(stubHostResolver());

    try {
        $executor->execute(safetyOperation(), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.redirect_limit');
    }
});

it('refuses HTTPS-to-HTTP downgrade redirect unless explicitly allowed (S2)', function (): void {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->once()
        ->andReturn(new Response(302, ['Location' => 'http://insecure.example.com/items']));

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory()))->withHostResolver(stubHostResolver());

    try {
        $executor->execute(safetyOperation('https://api.example.com'), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.downgrade_refused');
    }

    // Opting into allowDowngrade permits the hop
    $allowClient = Mockery::mock(ClientInterface::class);
    $allowClient->shouldReceive('sendRequest')->twice()->andReturn(
        new Response(302, ['Location' => 'http://insecure.example.com/items']),
        new Response(200, [], '{"ok":true}'),
    );

    $allowPolicy = HttpPolicy::default()->withSafety(
        OutboundSafetyPolicy::default()->withRedirects(RedirectPolicy::default()->withAllowDowngrade(true)),
    );

    $allowExecutor = (new DefaultOpenApiExecutor($allowClient, new HttpFactory(), policy: $allowPolicy))->withHostResolver(stubHostResolver());
    expect($allowExecutor->execute(safetyOperation('https://api.example.com'), new OpenApiPayload())->getStatusCode())->toBe(200);
});

it('aborts oversized response bodies on Content-Length and during streaming without full buffering (S3)', function (): void {
    // Case 1: fast-reject via Content-Length without reading stream
    $unreadStream = Mockery::mock(StreamInterface::class);
    $unreadStream->shouldNotReceive('read');
    $unreadStream->shouldNotReceive('getContents');
    $unreadStream->shouldReceive('close')->once();

    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->once()
        ->andReturn(new Response(200, ['Content-Length' => '5242880'], $unreadStream));

    $policy = HttpPolicy::default()->withSafety(
        OutboundSafetyPolicy::default()->withTransfer(TransferPolicy::default()->withMaxResponseBytes(1024)),
    );

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory(), policy: $policy))->withHostResolver(stubHostResolver());

    try {
        $executor->execute(safetyOperation(), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.response_too_large');
    }

    // Case 2: chunked response without Content-Length aborts during streaming and never reads more than maxResponseBytes + 1
    $reads = 0;
    $requestedLengths = [];
    $chunkedStream = Mockery::mock(StreamInterface::class);
    $chunkedStream->shouldReceive('isSeekable')->andReturn(false);
    $chunkedStream->shouldReceive('eof')->andReturnUsing(function () use (&$reads): bool {
        return $reads >= 10;
    });
    $chunkedStream->shouldReceive('read')->andReturnUsing(function (int $length) use (&$reads, &$requestedLengths): string {
        $reads++;
        $requestedLengths[] = $length;

        return str_repeat('A', min($length, 600));
    });
    $chunkedStream->shouldReceive('close')->once();

    $streamClient = Mockery::mock(ClientInterface::class);
    $streamClient->shouldReceive('sendRequest')->once()
        ->andReturn(new Response(200, [], $chunkedStream));

    $streamExecutor = (new DefaultOpenApiExecutor($streamClient, new HttpFactory(), policy: $policy))->withHostResolver(stubHostResolver());

    try {
        $streamExecutor->execute(safetyOperation(), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.response_too_large')
            ->and($reads)->toBe(2)
            ->and($requestedLengths)->toBe([1025, 425]);
    }
});

it('refuses disallowed URI schemes before dispatch (S4)', function (): void {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldNotReceive('sendRequest');

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory()))->withHostResolver(stubHostResolver());

    try {
        $executor->execute(safetyOperation('file:///etc'), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.scheme_refused');
    }
});

it('refuses denied hosts, link-local metadata redirects, and DNS-to-metadata SSRF (S5)', function (): void {
    // Case 1: Redirect to link-local AWS metadata IP blocked by default
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->once()
        ->andReturn(new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']));

    $allowDowngrade = HttpPolicy::default()->withSafety(
        OutboundSafetyPolicy::default()->withRedirects(RedirectPolicy::default()->withAllowDowngrade(true)),
    );

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory(), policy: $allowDowngrade))->withHostResolver(stubHostResolver());

    try {
        $executor->execute(safetyOperation('https://api.example.com'), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.denied_host');
    }

    // Case 2: Hostname resolving via DNS to link-local metadata IP blocked before dispatch
    $dnsClient = Mockery::mock(ClientInterface::class);
    $dnsClient->shouldNotReceive('sendRequest');

    $dnsExecutor = (new DefaultOpenApiExecutor($dnsClient, new HttpFactory()))
        ->withHostResolver(stubHostResolver(['evil.example.com' => ['169.254.169.254']]));

    try {
        $dnsExecutor->execute(safetyOperation('https://evil.example.com'), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.denied_host');
    }
});

it('follows RFC 9110 redirects and strips credentials on cross-origin hops', function (): void {
    $captured = [];
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->twice()->andReturnUsing(function (RequestInterface $req) use (&$captured): Response {
        $captured[] = $req;

        if (count($captured) === 1) {
            return new Response(303, ['Location' => 'https://cdn.other.example/resource']);
        }

        return new Response(200, [], '{"ok":true}');
    });

    $op = safetyOperation('https://api.example.com', 'petstore', 'post', [
        'components' => [
            'securitySchemes' => [
                'api_key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
            ],
        ],
        'security' => [['api_key' => []]],
    ]);

    $sourcePolicy = SourcePolicy::default()
        ->withCredential('api_key', Credential::apiKey('SECRET-KEY'))
        ->withHeader('Authorization', 'Bearer SECRET-TOK')
        ->withHeader('Cookie', 'session=secret');

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory()))->withHostResolver(stubHostResolver());
    $response = $executor->execute($op, new OpenApiPayload(body: ['a' => 1]), sourcePolicy: $sourcePolicy);

    expect($response->getStatusCode())->toBe(200)
        ->and($captured)->toHaveCount(2)
        ->and($captured[0]->getMethod())->toBe('POST')
        ->and($captured[0]->getHeaderLine('X-Api-Key'))->toBe('SECRET-KEY')
        ->and($captured[1]->getMethod())->toBe('GET')
        ->and((string) $captured[1]->getBody())->toBe('')
        ->and($captured[1]->hasHeader('Authorization'))->toBeFalse()
        ->and($captured[1]->hasHeader('Cookie'))->toBeFalse()
        ->and($captured[1]->hasHeader('X-Api-Key'))->toBeFalse();
});

it('applies per-source HttpPolicy overrides resolved from the operation source name', function (): void {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->once()->andReturn(new Response(200, [], Utils::streamFor('ok')));

    $policy = HttpPolicy::default()
        ->withSourceOverride(
            'payments',
            HttpPolicy::default()->withSafety(
                OutboundSafetyPolicy::default()->withNetwork(
                    NetworkPolicy::default()->withAllowedSchemes(['https'])->withAllowedHosts(['api.payments.example']),
                ),
            ),
        );

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory(), policy: $policy))->withHostResolver(stubHostResolver());

    // petstore uses default policy (allows http)
    expect($executor->execute(safetyOperation('http://api.petstore.example', 'petstore'), new OpenApiPayload())->getStatusCode())->toBe(200);

    // payments override refuses http scheme
    try {
        $executor->execute(safetyOperation('http://api.payments.example', 'payments'), new OpenApiPayload());
        test()->fail('Expected HttpPolicyViolationException');
    } catch (HttpPolicyViolationException $e) {
        expect($e->getErrorCode())->toBe('transport.scheme_refused');
    }
});

it('preserves method and rewinds consumed body on 307 redirects, resolves relative Location, and preserves credentials same-origin', function (): void {
    $captured = [];
    $capturedBodies = [];
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->twice()->andReturnUsing(function (RequestInterface $req) use (&$captured, &$capturedBodies): Response {
        $captured[] = $req;
        // Simulate a PSR-18 client draining the request body stream during hop dispatch.
        $capturedBodies[] = $req->getBody()->getContents();
        if (count($captured) === 1) {
            return new Response(307, ['Location' => '/v2/items']);
        }

        return new Response(200, [], '{"ok":true}');
    });

    $op = safetyOperation('https://api.example.com', 'petstore', 'post');
    $sourcePolicy = SourcePolicy::default()->withHeader('Authorization', 'Bearer SAME-ORIGIN');

    $executor = (new DefaultOpenApiExecutor($client, new HttpFactory()))->withHostResolver(stubHostResolver());
    $response = $executor->execute($op, new OpenApiPayload(body: ['a' => 1]), sourcePolicy: $sourcePolicy);

    expect($response->getStatusCode())->toBe(200)
        ->and($captured)->toHaveCount(2)
        ->and((string) $captured[1]->getUri())->toBe('https://api.example.com/v2/items')
        ->and($captured[1]->getMethod())->toBe('POST')
        ->and($capturedBodies)->toBe(['{"a":1}', '{"a":1}'])
        ->and($captured[1]->getHeaderLine('Authorization'))->toBe('Bearer SAME-ORIGIN');
});

it('passes allow_redirects=false, stream=true, and connect_timeout to Guzzle clients', function (): void {
    $capturedOptions = null;
    $guzzle = Mockery::mock(Client::class);
    $guzzle->shouldReceive('request')->once()->withArgs(function ($method, $uri, $options) use (&$capturedOptions): bool {
        $capturedOptions = $options;

        return true;
    })->andReturn(new Response(200, [], '{"ok":true}'));

    $policy = HttpPolicy::default()->withSafety(
        OutboundSafetyPolicy::default()->withTransfer(TransferPolicy::default()->withConnectTimeout(4.5)),
    );

    $executor = (new DefaultOpenApiExecutor($guzzle, new HttpFactory(), policy: $policy))->withHostResolver(stubHostResolver());
    $executor->execute(safetyOperation(), new OpenApiPayload(), timeoutSeconds: 12.0);

    expect($capturedOptions['allow_redirects'])->toBeFalse()
        ->and($capturedOptions['stream'])->toBeTrue()
        ->and($capturedOptions['http_errors'])->toBeFalse()
        ->and($capturedOptions['connect_timeout'])->toBe(4.5)
        ->and($capturedOptions['timeout'])->toBe(12.0);
});

it('covers HostResolver IP-literal short-circuit and NetworkPolicy CIDR and link-local toggle edges', function (): void {
    $resolver = new HostResolver();
    expect($resolver->resolveIps(''))->toBe([])
        ->and($resolver->resolveIps('127.0.0.1'))->toBe([])
        ->and($resolver->resolveIps('[::1]'))->toBe([]);

    $allowLinkLocal = NetworkPolicy::default()->withBlockLinkLocal(false);
    expect($allowLinkLocal->allowsIp('169.254.169.254'))->toBeTrue()
        ->and($allowLinkLocal->allowsIp('not-an-ip'))->toBeFalse()
        ->and($allowLinkLocal->allowsHost(''))->toBeFalse();

    $cidrDenied = NetworkPolicy::default()->withDeniedHosts(['10.20.0.0/16', '2001:db8::/32', 'invalid/cidr', '10.0.0.0/99']);
    expect($cidrDenied->allowsIp('10.20.5.1'))->toBeFalse()
        ->and($cidrDenied->allowsIp('10.21.5.1'))->toBeTrue()
        ->and($cidrDenied->allowsIp('2001:db8::1'))->toBeFalse()
        ->and($cidrDenied->allowsIp('2001:db9::1'))->toBeTrue()
        ->and($cidrDenied->allowsHost('[::ffff:10.20.1.2]'))->toBeFalse();
});
