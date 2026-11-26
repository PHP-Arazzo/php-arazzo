<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolution;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\YamlPolicy;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Exceptions\UnresolvableReferenceException;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceFetcher;

it('resolves http url using http fetcher and decodes json', function (): void {
    $httpFetcher = new class() implements SourceFetcher
    {
        public bool $called = false;

        public function fetch(string $urlOrPath, string $basePath): string
        {
            $this->called = true;

            return '{"test": "content"}';
        }
    };
    $resolver = new DefaultSourceResolver(['http' => $httpFetcher]);
    $source = new SourceDescription('test', 'http://example.com/api.json', SourceType::Openapi);
    $result = $resolver->resolve($source, '/base');

    expect($result->name)->toBe('test')
        ->and($result->canonicalUri)->toBe('http://example.com/api.json')
        ->and($result->content)->toBe(['test' => 'content'])
        ->and($httpFetcher->called)->toBeTrue();
});

it('routes url with no scheme to file fetcher', function (): void {
    $fileFetcher = new class() implements SourceFetcher
    {
        public bool $called = false;

        public function fetch(string $urlOrPath, string $basePath): string
        {
            $this->called = true;

            return '{"foo": "bar"}';
        }
    };
    $resolver = new DefaultSourceResolver(['file' => $fileFetcher]);
    $source = new SourceDescription('local', './local/api.json', SourceType::Openapi);
    $result = $resolver->resolve($source, '/base');

    expect($fileFetcher->called)->toBeTrue()
        ->and($result->canonicalUri)->toBe('file:///base/local/api.json');
});

it('throws SourceParseException when content is malformed', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{invalid json}';
        }
    };
    $resolver = new DefaultSourceResolver(['http' => $fetcher]);
    $source = new SourceDescription('test', 'http://example.com/api.json', SourceType::Openapi);

    expect(fn () => $resolver->resolve($source, '/base'))
        ->toThrow(SourceParseException::class);
});

it('throws SourceFetchException for unknown scheme', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{}';
        }
    };
    $resolver = new DefaultSourceResolver(['http' => $fetcher]);
    $source = new SourceDescription('s3', 's3://my-bucket/api.json', SourceType::Openapi);

    expect(fn () => $resolver->resolve($source, '/base'))
        ->toThrow(SourceFetchException::class, 's3');
});

it('resolves relative URLs with parent directories to canonical URIs', function (): void {
    $fileFetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"foo": "bar"}';
        }
    };
    $resolver = new DefaultSourceResolver(['file' => $fileFetcher]);
    $source = new SourceDescription('local', '../sibling/api.json', SourceType::Openapi);
    $result = $resolver->resolve($source, '/path/to/base');

    expect($result->canonicalUri)->toBe('file:///path/to/sibling/api.json');
});

it('bounds default remote fetches so an unreachable source cannot hang validation', function (): void {
    $config = DefaultSourceResolver::defaultHttpClient()->getConfig();

    expect($config['connect_timeout'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and($config['timeout'])->toBeFloat()->toBeGreaterThan(0.0);
});

it('applies yaml policy to source documents', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return "a: &a [1, 2]\nb: &b [*a, *a]\nc: &c [*b, *b]\nd: [*c, *c]\n";
        }
    };

    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withMaxAliases(5),
    );
    $resolver = new DefaultSourceResolver(['file' => $fetcher], $policy);
    $source = new SourceDescription('openapi', './openapi.yaml', SourceType::Openapi);

    expect(fn () => $resolver->resolve($source, '/base'))
        ->toThrow(SourceParseException::class, 'maxAliases');
});

it('creates resolver with custom fetchers via withFetchers', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"ok": true}';
        }
    };
    $resolver = DefaultSourceResolver::withFetchers(['file' => $fetcher]);
    $source = new SourceDescription('local', 'file.json', SourceType::Openapi);
    $result = $resolver->resolve($source, '/base');

    expect($result->content)->toBe(['ok' => true]);
});

it('throws SourceParseException when decoded document is not an array', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '"just a string"';
        }
    };
    $resolver = new DefaultSourceResolver(['file' => $fetcher]);
    $source = new SourceDescription('local', 'file.json', SourceType::Openapi);

    expect(fn () => $resolver->resolve($source, '/base'))
        ->toThrow(SourceParseException::class, 'must be an array');
});

it('registers and retrieves source documents by name', function (): void {
    $resolver = new DefaultSourceResolver([]);
    $doc = new SourceDocument('seeded', SourceType::Openapi, 'memory://seeded', ['openapi' => '3.0.0']);

    expect($resolver->get('seeded'))->toBeNull();

    $resolver->register($doc);

    expect($resolver->get('seeded'))->toBe($doc);
});

it('memoizes resolved documents so fetcher is called only once', function (): void {
    $fetchCount = 0;
    $fetcher = new class($fetchCount) implements SourceFetcher
    {
        public function __construct(public int &$count) {}

        public function fetch(string $urlOrPath, string $basePath): string
        {
            $this->count++;

            return '{"openapi": "3.0.0"}';
        }
    };

    $resolver = new DefaultSourceResolver(['file' => $fetcher]);
    $source = new SourceDescription('memo-api', 'api.json', SourceType::Openapi);

    $doc1 = $resolver->resolve($source, '/base');
    $doc2 = $resolver->resolve($source, '/base');

    expect($doc1)->toBe($doc2)
        ->and($fetchCount)->toBe(1)
        ->and($resolver->get('memo-api'))->toBe($doc1);
});

it('detects circular references during source acquisition', function (): void {
    $resolverRef = null;
    $fetcher = new class($resolverRef) implements SourceFetcher
    {
        public ?DefaultSourceResolver $resolver = null;

        public function __construct(?DefaultSourceResolver &$ref)
        {
            $this->resolver = &$ref;
        }

        public function fetch(string $urlOrPath, string $basePath): string
        {
            // Trigger a resolution of the SAME source to simulate circular dependency
            $this->resolver->resolve(new SourceDescription('circular-api', 'api.json', SourceType::Openapi), $basePath);

            return '{"openapi": "3.0.0"}';
        }
    };

    $resolver = new DefaultSourceResolver(['file' => $fetcher]);
    $resolverRef = $resolver;

    $source = new SourceDescription('circular-api', 'api.json', SourceType::Openapi);

    expect(fn () => $resolver->resolve($source, '/base'))
        ->toThrow(UnresolvableReferenceException::class, "Circular reference detected when resolving source 'circular-api'");
});

it('preserves seeded documents across withFetcher() while clearing fetched cache', function (): void {
    $firstFetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"source": "first"}';
        }
    };
    $secondFetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"source": "second"}';
        }
    };

    $seeded = new SourceDocument('seeded', SourceType::Openapi, 'memory://seeded', ['seeded' => true]);
    $resolver = (new DefaultSourceResolver(['file' => $firstFetcher]))->withDocument($seeded);

    // Fetch a remote/file doc into $cache
    $fetched1 = $resolver->resolve(new SourceDescription('dynamic', 'api.json', SourceType::Openapi), '/base');
    expect($fetched1->content)->toBe(['source' => 'first']);

    // Rebinding fetcher must preserve $seeded and clear $cache
    $rebound = $resolver->withFetcher('file', $secondFetcher);
    $fetched2 = $rebound->resolve(new SourceDescription('dynamic', 'api.json', SourceType::Openapi), '/base');
    $resolvedSeeded = $rebound->resolve(new SourceDescription('seeded', 'ignored.json', SourceType::Openapi), '/base');

    expect($fetched2->content)->toBe(['source' => 'second'])
        ->and($resolvedSeeded)->toBe($seeded)
        ->and($rebound->get('seeded'))->toBe($seeded);
});

it('captures self uri (S1)', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"arazzo": "1.1.0", "$self": "https://example.com/doc.arazzo", "info": {"title": "T", "version": "1.0"}}';
        }
    };

    $resolver = new DefaultSourceResolver(['http' => $fetcher]);
    $source = new SourceDescription('doc', 'http://example.com/doc.arazzo', SourceType::Arazzo);
    $result = $resolver->resolve($source, 'http://example.com');

    expect($result->selfUri)->toBe('https://example.com/doc.arazzo')
        ->and($result->canonicalUri)->toBe('http://example.com/doc.arazzo')
        ->and($result->baseUri())->toBe('https://example.com/doc.arazzo');
});

it('resolves by self before location (S2)', function (): void {
    $fetchCount = 0;
    $fetcher = new class($fetchCount) implements SourceFetcher
    {
        public function __construct(public int &$count) {}

        public function fetch(string $urlOrPath, string $basePath): string
        {
            $this->count++;

            return '{"arazzo": "1.1.0"}';
        }
    };

    $indexedDoc = new SourceDocument(
        name: 'cached-copy',
        type: SourceType::Arazzo,
        canonicalUri: 'https://cache.example.com/copy.arazzo',
        content: ['arazzo' => '1.1.0'],
        selfUri: 'https://example.com/doc.arazzo',
    );

    $resolver = (new DefaultSourceResolver(['https' => $fetcher]))->withDocument($indexedDoc);

    // Resolving a different source description whose URL matches the known $self
    $source = new SourceDescription('payments', 'https://example.com/doc.arazzo', SourceType::Arazzo);
    $result = $resolver->resolve($source, 'https://example.com');

    expect($result)->toBe($indexedDoc)
        ->and($fetchCount)->toBe(0);
});

it('falls back to location on identity miss (S3)', function (): void {
    $fetchCount = 0;
    $fetcher = new class($fetchCount) implements SourceFetcher
    {
        public function __construct(public int &$count) {}

        public function fetch(string $urlOrPath, string $basePath): string
        {
            $this->count++;

            return '{"arazzo": "1.1.0", "info": {"title": "Fetched", "version": "1.0"}}';
        }
    };

    $resolver = new DefaultSourceResolver(['https' => $fetcher]);
    $source = new SourceDescription('fresh', 'https://example.com/fresh.arazzo', SourceType::Arazzo);
    $result = $resolver->resolve($source, 'https://example.com');

    expect($fetchCount)->toBe(1)
        ->and($result->name)->toBe('fresh')
        ->and($result->canonicalUri)->toBe('https://example.com/fresh.arazzo');
});

it('resolves relative self against retrieval uri (S5)', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"arazzo": "1.1.0", "$self": "./doc.arazzo"}';
        }
    };

    $resolver = new DefaultSourceResolver(['https' => $fetcher]);
    $source = new SourceDescription('rel-self', 'https://example.com/base/workflow.arazzo', SourceType::Arazzo);
    $result = $resolver->resolve($source, 'https://example.com/base/');

    expect($result->selfUri)->toBe('https://example.com/base/doc.arazzo')
        ->and($result->canonicalUri)->toBe('https://example.com/base/workflow.arazzo');
});

it('keeps retrieval base without self (S6)', function (): void {
    $fetcher = new class() implements SourceFetcher
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"openapi": "3.0.0"}';
        }
    };

    $resolver = new DefaultSourceResolver(['file' => $fetcher]);
    $source = new SourceDescription('no-self', './specs/api.json', SourceType::Openapi);
    $result = $resolver->resolve($source, '/workspace');

    expect($result->selfUri)->toBeNull()
        ->and($result->canonicalUri)->toBe('file:///workspace/specs/api.json')
        ->and($result->baseUri())->toBe('file:///workspace/specs/api.json');
});
