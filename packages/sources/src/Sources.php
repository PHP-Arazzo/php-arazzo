<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiSourceNormalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

final readonly class Sources implements SourcesInterface
{
    private SourceResolverInterface $resolver;

    /** @var array<string, SourceNormalizerInterface> */
    private array $normalizers;

    /**
     * @param  array<string, SourceNormalizerInterface>  $normalizers
     */
    public function __construct(
        ?SourceResolverInterface $resolver = null,
        array $normalizers = [],
    ) {
        $this->resolver = $resolver ?? DefaultSourceResolver::withDefaults();
        if ($normalizers === []) {
            $defaultNormalizer = new OpenApiSourceNormalizer(
                new OpenApiVersionDetector(),
                new OpenApi30Normalizer(),
                new OpenApi31Normalizer(),
            );
            $normalizers = [$defaultNormalizer->name() => $defaultNormalizer];
        }
        $this->normalizers = $normalizers;
    }

    public static function create(): self
    {
        return new self();
    }

    public function resolve(SourceDescription $source, string $basePath = ''): SourceDocument
    {
        return $this->resolver->resolve($source, $basePath);
    }

    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection
    {
        $operations = [];
        foreach ($document->sourceDescriptions as $source) {
            $normalizer = $this->findNormalizer($source->type);
            if ($normalizer === null) {
                continue;
            }

            try {
                $resolved = $this->resolve($source, $basePath);
                $rawContent = json_encode($resolved->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                /** @var array<string, ResolvedOperation> $normalized */
                $normalized = $normalizer->normalize($source, $rawContent, $document);
                $operations += $normalized;
            } catch (Throwable) {
                // Unreachable or unparseable source (R5): skip it, never fail
                // validation solely due to source resolution.
                continue;
            }
        }

        return ResolvedOperationCollection::fromArray($operations);
    }

    public function knownOperations(ArazzoDocument $document, string $basePath = ''): KnownOperations
    {
        return $this->normalizeSources($document, $basePath)->toKnownOperations();
    }

    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): static
    {
        $factory = $requestFactory ?? new HttpFactory();
        $httpFetcher = new HttpFetcher($client, $factory);

        return $this
            ->withFetcher('http', $httpFetcher)
            ->withFetcher('https', $httpFetcher);
    }

    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): static
    {
        $resolver = $this->resolver instanceof DefaultSourceResolver
            ? $this->resolver->withFetcher($scheme, $fetcher)
            : $this->resolver;

        return new self($resolver, $this->normalizers);
    }

    public function withNormalizer(SourceNormalizerInterface $normalizer): static
    {
        $normalizers = $this->normalizers;
        $normalizers[$normalizer->name()] = $normalizer;

        return new self($this->resolver, $normalizers);
    }

    private function findNormalizer(SourceType $type): ?SourceNormalizerInterface
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($type)) {
                return $normalizer;
            }
        }

        return null;
    }
}
