<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

it('defines the SourceFetcherInterface SPI contract', function (): void {
    $fetcher = new class() implements SourceFetcherInterface
    {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return "fetched: {$urlOrPath} from {$basePath}";
        }
    };

    expect($fetcher->fetch('spec.yaml', '/base'))->toBe('fetched: spec.yaml from /base');
});

it('defines the SourceResolverInterface SPI contract', function (): void {
    $resolver = new class() implements SourceResolverInterface
    {
        public function resolve(SourceDescription $source, string $basePath): SourceDocument
        {
            return new SourceDocument(
                name: $source->name,
                type: $source->type,
                canonicalUri: "file://{$basePath}/{$source->url}",
                content: ['openapi' => '3.0.0'],
            );
        }
    };

    $source = new SourceDescription('test', 'test.yaml', SourceType::Openapi);
    $resolved = $resolver->resolve($source, '/workspace');

    expect($resolved->name)->toBe('test')
        ->and($resolved->canonicalUri)->toBe('file:///workspace/test.yaml')
        ->and($resolved->content)->toBe(['openapi' => '3.0.0']);
});
