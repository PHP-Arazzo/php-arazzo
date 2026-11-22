<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolution;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\YamlPolicy;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
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
