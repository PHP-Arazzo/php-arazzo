<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolution\Fetchers;

use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;

it('fetches an http url', function (): void {
    $client = \Mockery::mock(ClientInterface::class);
    $reqFactory = \Mockery::mock(RequestFactoryInterface::class);
    $request = \Mockery::mock(RequestInterface::class);
    $response = \Mockery::mock(ResponseInterface::class);

    $stream = \Mockery::mock(StreamInterface::class);
    $stream->shouldReceive('__toString')->andReturn('fetched content');
    $reqFactory->shouldReceive('createRequest')->with('GET', 'http://example.com/spec.yaml')->andReturn($request);
    $client->shouldReceive('sendRequest')->with($request)->andReturn($response);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getBody')->andReturn($stream);

    $fetcher = new HttpFetcher($client, $reqFactory);

    expect($fetcher->fetch('http://example.com/spec.yaml', ''))->toBe('fetched content');
});

it('throws SourceFetchException on http error', function (): void {
    $client = \Mockery::mock(ClientInterface::class);
    $reqFactory = \Mockery::mock(RequestFactoryInterface::class);
    $request = \Mockery::mock(RequestInterface::class);
    $response = \Mockery::mock(ResponseInterface::class);

    $reqFactory->shouldReceive('createRequest')->with('GET', 'http://example.com/spec.yaml')->andReturn($request);
    $client->shouldReceive('sendRequest')->with($request)->andReturn($response);
    $response->shouldReceive('getStatusCode')->andReturn(404);

    $fetcher = new HttpFetcher($client, $reqFactory);

    expect(fn () => $fetcher->fetch('http://example.com/spec.yaml', ''))
        ->toThrow(SourceFetchException::class, 'HTTP request failed for http://example.com/spec.yaml: 404');
});

it('resolves relative URL against an HTTP basePath and rejects non-HTTP basePath', function (): void {
    $client = \Mockery::mock(ClientInterface::class);
    $reqFactory = \Mockery::mock(RequestFactoryInterface::class);
    $request = \Mockery::mock(RequestInterface::class);
    $response = \Mockery::mock(ResponseInterface::class);
    $stream = \Mockery::mock(StreamInterface::class);

    $stream->shouldReceive('__toString')->andReturn('ok');
    $reqFactory->shouldReceive('createRequest')->with('GET', 'https://api.example.com/specs/petstore.yaml')->andReturn($request);
    $client->shouldReceive('sendRequest')->with($request)->andReturn($response);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getBody')->andReturn($stream);

    $fetcher = new HttpFetcher($client, $reqFactory);

    expect($fetcher->fetch('petstore.yaml', 'https://api.example.com/specs/'))->toBe('ok');
    expect(fn () => $fetcher->fetch('petstore.yaml', '/local/dir'))
        ->toThrow(SourceFetchException::class, 'Cannot resolve relative URL');
});

it('wraps PSR-18 client exceptions, enforces FetchPolicy maxBytes, and injects W3C trace headers with logs', function (): void {
    $capturedHeaders = [];
    $spans = [];
    $logs = [];

    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<array{level: mixed, message: string}> $logs */
        public function __construct(public array &$logs) {}

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => $level, 'message' => (string) $message];
        }
    };

    $tracer = new class($spans) implements TracerInterface
    {
        /** @param list<array{name: string, status: ?string, ended: bool}> $spans */
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

                public function recordException(\Throwable $exception): void {}

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

    $okClient = new class($capturedHeaders) implements ClientInterface
    {
        /** @param array<string, list<string>> $captured */
        public function __construct(public array &$captured) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->captured = $request->getHeaders();

            return new Response(200, [], '1234567890');
        }
    };

    // 1. Success path with traceparent injection
    $fetcher = new HttpFetcher(
        $okClient,
        new HttpFactory(),
        FetchPolicy::default()->withMaxBytes(10),
        $logger,
        $tracer,
    );
    expect($fetcher->fetch('https://example.com/spec.yaml', ''))->toBe('1234567890')
        ->and($capturedHeaders['traceparent'] ?? null)->toBe(['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->and($spans[0])->toBe(['name' => 'arazzo.source.fetch', 'status' => true, 'ended' => true]);

    // 2. Exceeds maxBytes
    $smallFetcher = new HttpFetcher(
        $okClient,
        new HttpFactory(),
        FetchPolicy::default()->withMaxBytes(5),
        $logger,
        $tracer,
    );
    expect(fn () => $smallFetcher->fetch('https://example.com/spec.yaml', ''))
        ->toThrow(SourceFetchException::class, 'exceeded maximum size of 5 bytes');
    expect($spans[1]['status'])->toBeFalse()->and($spans[1]['ended'])->toBeTrue();

    // 3. PSR-18 connection failure
    $failingClient = new class() implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        }
    };
    $failingFetcher = new HttpFetcher($failingClient, null, null, $logger, $tracer);
    expect(fn () => $failingFetcher->fetch('https://example.com/down.yaml', ''))
        ->toThrow(SourceFetchException::class, 'HTTP connection failed');
    expect($spans[2]['status'])->toBeFalse()->and($spans[2]['ended'])->toBeTrue()
        ->and(count($logs))->toBeGreaterThanOrEqual(3);
});
