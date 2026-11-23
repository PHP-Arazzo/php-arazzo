<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use GuzzleHttp\Client;

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
