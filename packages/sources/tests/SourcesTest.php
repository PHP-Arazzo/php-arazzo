<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Observability\NullSpan;
use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

it('creates a Sources instance implementing SourcesInterface', function (): void {
    $sources = Sources::create();

    expect($sources)->toBeInstanceOf(SourcesInterface::class)
        ->and($sources)->toBeInstanceOf(Sources::class);
});

it('resolves a local source description into a SourceDocument', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    $source = new SourceDescription('petstore', $file, SourceType::Openapi);

    $sources = Sources::create();
    $doc = $sources->resolve($source, __DIR__);

    expect($doc)->toBeInstanceOf(SourceDocument::class)
        ->and($doc->name)->toBe('petstore')
        ->and($doc->type)->toBe(SourceType::Openapi)
        ->and($doc->content)->toBeArray()
        ->and($doc->content)->toHaveKey('openapi');
});

it('normalizes sources declared by an ArazzoDocument into a ResolvedOperationCollection', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    $source = new SourceDescription('petstore', $file, SourceType::Openapi);

    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [$source],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $sources = Sources::create();
    $operations = $sources->normalizeSources($arazzoDoc, __DIR__);

    expect($operations)->toBeInstanceOf(ResolvedOperationCollection::class)
        ->and(count($operations))->toBeGreaterThan(0)
        ->and($operations->has('listPets'))->toBeTrue()
        ->and($operations->has('$sourceDescriptions.petstore.listPets'))->toBeTrue();
});

it('derives KnownOperations suitable for Rule 03 validation', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    $source = new SourceDescription('petstore', $file, SourceType::Openapi);

    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [$source],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $sources = Sources::create();
    $known = $sources->knownOperations($arazzoDoc, __DIR__);

    expect($known)->toBeInstanceOf(KnownOperations::class)
        ->and($known->has('petstore', 'listPets'))->toBeTrue();
});

it('supports immutable rebinding of HTTP client and fetchers', function (): void {
    $sources = Sources::create();

    $mockClient = new Client();
    $bound = $sources->withHttpClient($mockClient);

    expect($bound)->not->toBe($sources)
        ->and($bound)->toBeInstanceOf(Sources::class);

    $customFetcher = new class() implements SourceFetcherInterface
    {
        public function fetch(string $url, string $basePath): string
        {
            return '{"openapi": "3.0.0", "info": {"title": "custom", "version": "1.0"}, "paths": {}}';
        }
    };

    $withCustom = $bound->withFetcher('custom', $customFetcher);
    expect($withCustom)->not->toBe($bound)
        ->and($withCustom)->toBeInstanceOf(Sources::class);
});

it('supports immutable addition of custom source normalizers', function (): void {
    $sources = Sources::create();

    $customNormalizer = new class() implements SourceNormalizerInterface
    {
        public function name(): string
        {
            return 'custom-norm';
        }

        public function priority(): int
        {
            return 50;
        }

        public function supports(SourceType $type): bool
        {
            return false;
        }

        public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
        {
            return [];
        }
    };

    $withNorm = $sources->withNormalizer($customNormalizer);
    expect($withNorm)->not->toBe($sources)
        ->and($withNorm)->toBeInstanceOf(Sources::class);
});

it('skips unresolvable sources gracefully in normalizeSources per R5', function (): void {
    $source = new SourceDescription('missing', '/path/to/nonexistent/openapi.json', SourceType::Openapi);

    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [$source],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $sources = Sources::create();
    $operations = $sources->normalizeSources($arazzoDoc, __DIR__);

    expect($operations)->toBeInstanceOf(ResolvedOperationCollection::class)
        ->and(count($operations))->toBe(0);
});

it('throws SourceFetchException when resolving an unknown scheme directly', function (): void {
    $source = new SourceDescription('bad', 'unknown://foo.bar', SourceType::Openapi);

    $sources = Sources::create();
    $sources->resolve($source);
})->throws(SourceFetchException::class);

it('immutably seeds in-memory documents via withDocument() and preserves them across withHttpClient()', function (): void {
    $seeded = new SourceDocument('petstore', SourceType::Openapi, 'https://mini.test/openapi.json', [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Mini', 'version' => '1.0'],
        'paths' => [],
    ]);

    $base = Sources::create();
    $withDoc = $base->withDocument($seeded);
    $rebound = $withDoc->withHttpClient(new Client());

    $desc = new SourceDescription('petstore', 'https://mini.test/openapi.json', SourceType::Openapi);

    expect($withDoc)->not->toBe($base)
        ->and($withDoc->resolve($desc))->toBe($seeded)
        ->and($rebound->resolve($desc))->toBe($seeded);
});

afterEach(function (): void {
    SourceNormalizerRegistry::reset();
});

it('resolves normalizers from SourceNormalizerRegistry by default and lets withNormalizer() override per instance', function (): void {
    $globalCalled = false;
    $globalNormalizer = new class($globalCalled) implements SourceNormalizerInterface
    {
        public function __construct(public bool &$called) {}

        public function name(): string
        {
            return 'global-wsdl';
        }

        public function priority(): int
        {
            return 100;
        }

        public function supports(SourceType $type): bool
        {
            return $type === SourceType::Wsdl;
        }

        public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
        {
            $this->called = true;

            return [];
        }
    };

    $localCalled = false;
    $localNormalizer = new class($localCalled) implements SourceNormalizerInterface
    {
        public function __construct(public bool &$called) {}

        public function name(): string
        {
            return 'local-wsdl';
        }

        public function priority(): int
        {
            return 0;
        }

        public function supports(SourceType $type): bool
        {
            return $type === SourceType::Wsdl;
        }

        public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
        {
            $this->called = true;

            return [];
        }
    };

    SourceNormalizerRegistry::create()->register($globalNormalizer);

    $source = new SourceDescription('soap', 'https://soap.test/service.wsdl', SourceType::Wsdl);
    $seeded = new SourceDocument('soap', SourceType::Wsdl, 'https://soap.test/service.wsdl', ['wsdl' => true]);
    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [$source],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    // Default Sources picks up the globally registered normalizer
    $defaultSources = Sources::create()->withDocument($seeded);
    $defaultSources->normalizeSources($arazzoDoc);
    expect($globalCalled)->toBeTrue()
        ->and($localCalled)->toBeFalse();

    // Instance override via withNormalizer() takes precedence without mutating the registry
    $globalCalled = false;
    $overridden = $defaultSources->withNormalizer($localNormalizer);
    $overridden->normalizeSources($arazzoDoc);
    expect($localCalled)->toBeTrue()
        ->and($globalCalled)->toBeFalse();
});

it('enforces FetchPolicy allowedSchemes, maxBytes, and strictReachability while emitting logs and spans', function (): void {
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
            $carrier['traceparent'] = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';
        }

        public function extractContext(array $carrier): ?array
        {
            return null;
        }
    };

    $capturedTraceparent = null;
    $httpClient = new class($capturedTraceparent) implements ClientInterface
    {
        public function __construct(public ?string &$traceparent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->traceparent = $request->getHeaderLine('traceparent');

            return new Response(200, [], '{"openapi":"3.0.3","info":{"title":"T","version":"1"},"paths":{}}');
        }
    };

    $sources = Sources::create()
        ->withLogger($logger)
        ->withTracer($tracer)
        ->withHttpClient($httpClient)
        ->withPolicy(FetchPolicy::default()->withAllowedSchemes(['https']));

    $doc = $sources->resolve(new SourceDescription('remote', 'https://api.example.com/spec.json', SourceType::Openapi));
    expect($doc->name)->toBe('remote')
        ->and($capturedTraceparent)->toBe('00-0123456789abcdef0123456789abcdef-0123456789abcdef-01')
        ->and($spans)->toContain('arazzo.source.resolve')
        ->and($spans)->toContain('arazzo.source.fetch');

    // Disallowed scheme throws SourceFetchException and logs warning
    expect(fn () => $sources->resolve(new SourceDescription('insecure', 'http://api.example.com/spec.json', SourceType::Openapi)))
        ->toThrow(SourceFetchException::class, "Scheme 'http' is disallowed by FetchPolicy");

    // Local file maxBytes enforcement via DecodePolicy synced from FetchPolicy
    $tinyPolicySources = Sources::create()
        ->withLogger($logger)
        ->withPolicy(FetchPolicy::default()->withMaxBytes(10));
    expect(fn () => $tinyPolicySources->resolve(new SourceDescription('petstore', __DIR__.'/fixtures/document/openapi30.yaml', SourceType::Openapi)))
        ->toThrow(SourceParseException::class);

    // strictReachability=true fails normalizeSources instead of skipping
    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('missing', '/no/such/spec.yaml', SourceType::Openapi)],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $strictSources = Sources::create()
        ->withLogger($logger)
        ->withPolicy(FetchPolicy::default()->withStrictReachability());

    expect(fn () => $strictSources->normalizeSources($arazzoDoc, __DIR__))
        ->toThrow(SourceFetchException::class);
    expect($logs)->not->toBeEmpty();
});

it('preserves custom http fetcher across subsequent withPolicy/withLogger/withTracer calls and wraps non-SourceResolutionException when strict', function (): void {
    $customCalls = 0;
    $customHttpFetcher = new class($customCalls) implements SourceFetcherInterface
    {
        public function __construct(public int &$calls) {}

        public function fetch(string $url, string $basePath): string
        {
            $this->calls++;

            return '{"openapi":"3.0.0","info":{"title":"Custom","version":"1"},"paths":{}}';
        }
    };

    $sources = Sources::create()
        ->withFetcher('https', $customHttpFetcher)
        ->withPolicy(FetchPolicy::default()->withTimeout(3.0))
        ->withLogger(new NullLogger())
        ->withTracer(new NullTracer());

    $doc = $sources->resolve(new SourceDescription('api', 'https://custom.test/spec.json', SourceType::Openapi));
    expect($customCalls)->toBe(1)
        ->and($doc->content['info']['title'])->toBe('Custom');

    // Non-DefaultSourceResolver custom resolver stays intact on withPolicy() and wraps RuntimeException under strictReachability
    $failingResolver = new class() implements SourceResolverInterface
    {
        public function resolve(SourceDescription $source, string $basePath): SourceDocument
        {
            throw new \RuntimeException('boom');
        }
    };

    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('broken', 'https://broken.test/spec.json', SourceType::Openapi)],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $strictCustom = (new Sources($failingResolver))
        ->withPolicy(FetchPolicy::default()->withStrictReachability());

    expect(fn () => $strictCustom->normalizeSources($arazzoDoc))
        ->toThrow(SourceFetchException::class, "Failed to resolve source 'broken': boom");

    // Non-DefaultSourceResolver preserves custom resolver on withFetcher()/withDocument()
    $seededDoc = new SourceDocument('seeded', SourceType::Openapi, 'https://seeded.test/spec.json', ['openapi' => '3.0.0']);
    $withCustomOps = (new Sources($failingResolver))
        ->withFetcher('custom', $customHttpFetcher)
        ->withDocument($seededDoc);
    expect($withCustomOps->resolve(new SourceDescription('seeded', 'https://seeded.test/spec.json', SourceType::Openapi)))
        ->toBe($seededDoc);

    // normalizeSources skips SourceTypes that have no registered normalizer (e.g. Arazzo)
    $arazzoOnly = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('sub', 'https://sub.test/arazzo.yaml', SourceType::Arazzo)],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
    expect(count(Sources::create()->normalizeSources($arazzoOnly)))->toBe(0);
});
