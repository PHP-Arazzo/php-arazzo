<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use Alama\Arazzo\Sources\Resolver\SourcesRuntimeConfig;
use Alama\Arazzo\Sources\Resolver\UriReferenceResolver;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class Sources implements SourcesInterface
{
    private SourceResolverInterface $resolver;

    /** @var array<string, SourceNormalizerInterface> */
    private array $normalizers;

    /** @var array<string, SourceDocument> */
    private array $documents;

    private FetchPolicy $policy;

    private SourcesRuntimeConfig $runtime;

    /**
     * @param  array<string, SourceNormalizerInterface>  $normalizers
     * @param  array<string, SourceDocument>  $documents
     */
    public function __construct(
        ?SourceResolverInterface $resolver = null,
        array $normalizers = [],
        array $documents = [],
        ?FetchPolicy $policy = null,
        ?SourcesRuntimeConfig $runtime = null,
    ) {
        $this->policy = $policy ?? FetchPolicy::default();
        $this->runtime = $runtime ?? new SourcesRuntimeConfig();
        $this->resolver = $resolver ?? DefaultSourceResolver::withDefaults(
            DecodePolicy::default()->withMaxBytes($this->policy->maxBytes),
        );
        $this->normalizers = $normalizers;
        $this->documents = $documents;
    }

    public static function create(): self
    {
        return new self();
    }

    public function resolve(SourceDescription $source, string $basePath = ''): SourceDocument
    {
        $span = $this->runtime->tracer->startSpan('arazzo.source.resolve', [
            'arazzo.source.name' => $source->name,
            'arazzo.source.type' => $source->type === null ? 'unknown' : $source->type->value,
        ]);

        try {
            if (isset($this->documents[$source->name])) {
                $span->setAttribute('arazzo.source.seeded', true);
                $span->setStatus(true);

                return $this->documents[$source->name];
            }

            $this->assertAllowedScheme($source, $basePath);

            $document = $this->resolver->resolve($source, $basePath);
            $span->setAttribute('arazzo.source.seeded', false);
            $span->setStatus(true);

            return $document;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(false, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection
    {
        $effectiveBasePath = $basePath;
        if ($document->self !== null && trim($document->self) !== '') {
            $effectiveBasePath = UriReferenceResolver::resolve(trim($document->self), $basePath);
        }

        $operations = [];
        foreach ($document->sourceDescriptions as $source) {
            $operations += $this->normalizeSource($source, $document, $effectiveBasePath);
        }

        return ResolvedOperationCollection::fromArray($operations);
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    private function normalizeSource(
        SourceDescription $source,
        ArazzoDocument $document,
        string $basePath,
    ): array {
        $resolved = null;
        $type = $source->type;

        if ($type === null) {
            [$resolved, $type] = $this->inferSourceType($source, $basePath);
        }

        if ($type === null) {
            return [];
        }

        $normalizer = $this->findNormalizer($type);
        if ($normalizer === null) {
            return [];
        }

        $effectiveSource = $source->type === null
            ? new SourceDescription($source->name, $source->url, $type)
            : $source;

        try {
            $resolved ??= $this->resolve($source, $basePath);
            $rawContent = json_encode($resolved->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            /** @var array<string, ResolvedOperation> $normalized */
            $normalized = $normalizer->normalize($effectiveSource, $rawContent, $document);

            return $normalized;
        } catch (Throwable $e) {
            $this->runtime->logger->warning('Skipping unresolvable source during normalization.', [
                'source' => $source->name,
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);

            if ($this->policy->strictReachability) {
                throw $e instanceof SourceResolutionException
                    ? $e
                    : SourceFetchException::resolutionFailed($source->name, $e);
            }

            return [];
        }
    }

    /**
     * @return array{0: ?SourceDocument, 1: ?SourceType}
     */
    private function inferSourceType(SourceDescription $source, string $basePath): array
    {
        try {
            $resolved = $this->resolve($source, $basePath);
            $type = SourceDescription::inferFromContent($resolved->content);

            return [$resolved, $type];
        } catch (Throwable $e) {
            $this->runtime->logger->warning('Skipping unresolvable source during normalization.', [
                'source' => $source->name,
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);

            if ($this->policy->strictReachability) {
                throw $e instanceof SourceResolutionException
                    ? $e
                    : SourceFetchException::resolutionFailed($source->name, $e);
            }

            return [null, null];
        }
    }

    public function knownOperations(ArazzoDocument $document, string $basePath = ''): KnownOperations
    {
        return $this->normalizeSources($document, $basePath)->toKnownOperations();
    }

    public function withPolicy(FetchPolicy $policy): static
    {
        $resolver = $this->rebindResolver($this->resolver, $policy, $this->runtime);

        return new self($resolver, $this->normalizers, $this->documents, $policy, $this->runtime);
    }

    public function withLogger(LoggerInterface $logger): static
    {
        $runtime = $this->runtime->withLogger($logger);
        $resolver = $this->rebindResolver($this->resolver, $this->policy, $runtime);

        return new self($resolver, $this->normalizers, $this->documents, $this->policy, $runtime);
    }

    public function withTracer(TracerInterface $tracer): static
    {
        $runtime = $this->runtime->withTracer($tracer);
        $resolver = $this->rebindResolver($this->resolver, $this->policy, $runtime);

        return new self($resolver, $this->normalizers, $this->documents, $this->policy, $runtime);
    }

    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): static
    {
        $runtime = $this->runtime->withHttpClient($client, $requestFactory);
        $resolver = $this->rebindResolver($this->resolver, $this->policy, $runtime);

        return new self($resolver, $this->normalizers, $this->documents, $this->policy, $runtime);
    }

    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): static
    {
        $normalizedScheme = strtolower($scheme);
        $runtime = in_array($normalizedScheme, ['http', 'https'], true)
            ? $this->runtime->withCustomHttpFetcher()
            : $this->runtime;

        $resolver = $this->resolver instanceof DefaultSourceResolver
            ? $this->resolver->withFetcher($normalizedScheme, $fetcher)
            : $this->resolver;

        return new self($resolver, $this->normalizers, $this->documents, $this->policy, $runtime);
    }

    public function withNormalizer(SourceNormalizerInterface $normalizer): static
    {
        $normalizers = $this->normalizers;
        unset($normalizers[$normalizer->name()]);
        $normalizers[$normalizer->name()] = $normalizer;

        return new self($this->resolver, $normalizers, $this->documents, $this->policy, $this->runtime);
    }

    public function withDocument(SourceDocument $document): static
    {
        $documents = $this->documents;
        $documents[$document->name] = $document;

        $resolver = $this->resolver instanceof DefaultSourceResolver
            ? $this->resolver->withDocument($document)
            : $this->resolver;

        return new self($resolver, $this->normalizers, $documents, $this->policy, $this->runtime);
    }

    private function assertAllowedScheme(SourceDescription $source, string $basePath = ''): void
    {
        $canonicalTarget = UriReferenceResolver::resolve($source->url, $basePath);
        if (str_starts_with($canonicalTarget, 'file://')) {
            $scheme = 'file';
        } else {
            $parsed = parse_url($canonicalTarget, PHP_URL_SCHEME);
            if (is_string($parsed) && strlen($parsed) > 1) {
                $scheme = strtolower($parsed);
            } else {
                $scheme = $source->scheme();
            }
        }

        if (!$this->policy->allowsScheme($scheme)) {
            $this->runtime->logger->warning('Source scheme disallowed by FetchPolicy.', [
                'source' => $source->name,
                'scheme' => $scheme,
                'allowed_schemes' => $this->policy->allowedSchemes,
            ]);

            throw SourceFetchException::schemeDisallowed($scheme);
        }
    }

    private function rebindResolver(
        SourceResolverInterface $resolver,
        FetchPolicy $policy,
        SourcesRuntimeConfig $runtime,
    ): SourceResolverInterface {
        if (!$resolver instanceof DefaultSourceResolver) {
            return $resolver;
        }

        $resolver = $resolver->withDecodePolicy(DecodePolicy::default()->withMaxBytes($policy->maxBytes));

        if (!$runtime->customHttpFetcher) {
            $client = $runtime->httpClient ?? DefaultSourceResolver::defaultHttpClient(
                $policy->connectTimeout,
                $policy->timeout,
            );
            $factory = $runtime->requestFactory ?? new HttpFactory();
            $httpFetcher = new HttpFetcher($client, $factory, $policy, $runtime->logger, $runtime->tracer);
            $resolver = $resolver
                ->withFetcher('http', $httpFetcher)
                ->withFetcher('https', $httpFetcher);
        }

        return $resolver;
    }

    private function findNormalizer(SourceType $type): ?SourceNormalizerInterface
    {
        foreach (array_reverse($this->normalizers) as $normalizer) {
            if ($normalizer->supports($type)) {
                return $normalizer;
            }
        }

        return SourceNormalizerRegistry::create()->get($type);
    }
}
