<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolver;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Plugins\SourceNormalizer\OpenApiSourceNormalizer;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;

final class FakeNormalizer implements SourceNormalizerInterface
{
    /** @param list<SourceType> $types */
    public function __construct(
        private readonly string $fakeName,
        private readonly array $types,
    ) {}

    public function name(): string
    {
        return $this->fakeName;
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(SourceType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
    {
        return [];
    }
}

#[AsSourceNormalizerPlugin(SourceType::Wsdl)]
final class AttributedFakeNormalizer implements SourceNormalizerInterface
{
    public function name(): string
    {
        return 'attributed-wsdl';
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
        return [];
    }
}

beforeEach(function (): void {
    SourceNormalizerRegistry::reset();
});

afterEach(function (): void {
    SourceNormalizerRegistry::reset();
});

it('operates as a singleton with create() and reset() and auto-discovers OpenApiSourceNormalizer', function (): void {
    $a = SourceNormalizerRegistry::create();
    $b = SourceNormalizerRegistry::create();

    expect($a)->toBe($b)
        ->and($a->get(SourceType::Openapi))->toBeInstanceOf(OpenApiSourceNormalizer::class);

    SourceNormalizerRegistry::reset();
    $c = SourceNormalizerRegistry::create();

    expect($c)->not->toBe($a)
        ->and($c->get(SourceType::Openapi))->toBeInstanceOf(OpenApiSourceNormalizer::class);
});

it('returns null when no normalizer supports the requested source type', function (): void {
    $registry = SourceNormalizerRegistry::create();

    expect($registry->get(SourceType::Openapi))->toBeInstanceOf(OpenApiSourceNormalizer::class);
    expect($registry->get(SourceType::Asyncapi))->toBeNull();
});

it('returns the registered normalizer that supports the requested type', function (): void {
    $registry = SourceNormalizerRegistry::create();
    $openapi = new FakeNormalizer('openapi', [SourceType::Openapi]);
    $asyncapi = new FakeNormalizer('asyncapi', [SourceType::Asyncapi]);
    $registry->register($openapi);
    $registry->register($asyncapi);

    expect($registry->get(SourceType::Openapi))->toBe($openapi);
    expect($registry->get(SourceType::Asyncapi))->toBe($asyncapi);
});

it('resolves last-registered normalizer first when multiple normalizers support the same type', function (): void {
    $registry = SourceNormalizerRegistry::create();
    $first = new FakeNormalizer('first', [SourceType::Openapi]);
    $second = new FakeNormalizer('second', [SourceType::Openapi]);
    $registry->register($first);
    $registry->register($second);

    expect($registry->get(SourceType::Openapi))->toBe($second);
});

it('replaces existing normalizer for the SourceType declared via #[AsSourceNormalizerPlugin] attribute', function (): void {
    $registry = SourceNormalizerRegistry::create();
    $attributed = new AttributedFakeNormalizer();
    $registry->register($attributed);

    expect($registry->get(SourceType::Wsdl))->toBe($attributed);
});
