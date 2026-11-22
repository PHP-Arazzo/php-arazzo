<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Document\Document as DocumentFacade;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiDocumentLoader;
use Alama\Arazzo\Sources\Normalizer\OpenApiOperationResolver;
use Alama\Arazzo\Sources\Normalizer\OpenApiSourceNormalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceResolver;
use Alama\Arazzo\Sources\Resolver\SourceNormalizerRegistry;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Composition root for the *sources* package.
 * It is the only place that constructs Guzzle / PSR‑HTTP objects.
 */
final class SourceGraph
{
    private static ?SourceLoader $cachedLoader = null;

    public static function reset(): void
    {
        self::$cachedLoader = null;
        SourceNormalizerRegistry::reset();
    }

    /**
     * The default source resolver for this slice.
     *
     * Deliberately NOT memoised across calls. Callers that want one instance
     * across several entry points inject it (see `runtime()`).
     */
    public static function defaultResolver(): DefaultSourceResolver
    {
        return DefaultSourceResolver::withDefaults();
    }

    /**
     * @deprecated Use defaultResolver() instead.
     */
    public static function defaultRegistry(): DefaultSourceResolver
    {
        return self::defaultResolver();
    }

    /**
     * Create a source resolver using the given HTTP client and request factory.
     * This hides the fetcher implementation details from consumers.
     */
    public static function createResolver(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpFactory = null,
    ): DefaultSourceResolver {
        $client = $httpClient ?? DefaultSourceResolver::defaultHttpClient();
        $factory = $httpFactory ?? new HttpFactory();

        return DefaultSourceResolver::withFetchers([
            'http' => new HttpFetcher($client, $factory),
            'https' => new HttpFetcher($client, $factory),
            'file' => new LocalFetcher(),
        ]);
    }

    /**
     * @deprecated Use createResolver() instead.
     */
    public static function createRegistry(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpFactory = null,
    ): DefaultSourceResolver {
        return self::createResolver($httpClient, $httpFactory);
    }

    public static function document(): DocumentInterface
    {
        return DocumentFacade::create();
    }

    /** Source‑only loader (no generic pipeline) - cached singleton */
    public static function loader(?SourceResolver $registry = null): SourceLoader
    {
        // Only use cache when no custom registry is provided
        if ($registry === null && self::$cachedLoader !== null) {
            return self::$cachedLoader;
        }

        $defaultRegistry = $registry ?? self::defaultResolver();

        // Build normalizer registry
        $normalizerRegistry = SourceNormalizerRegistry::create();
        $normalizerRegistry->register(new OpenApiSourceNormalizer(
            new OpenApiDocumentLoader($defaultRegistry),
            new OpenApiVersionDetector(),
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        ));

        $loader = new SourceLoader(
            sources: $defaultRegistry,
            versionDetector: new OpenApiVersionDetector(),
            normalizers: $normalizerRegistry,
        );

        // Only cache when using default registry
        if ($registry === null) {
            self::$cachedLoader = $loader;
        }

        return $loader;
    }

    /**
     * Bundle both independent façades for the runner
     *
     * Pass `$operations` when the caller already holds a resolver, so the
     * document and the resolver share one transport instead of each building
     * a loader (and its document cache) of their own.
     *
     * @return SourceRuntime<OpenApiOperationResolver>
     */
    public static function runtime(
        ?SourceResolver $registry = null,
        ?OpenApiOperationResolver $operations = null,
    ): SourceRuntime {
        $sources = $registry ?? self::defaultResolver();

        $operations ??= self::buildOperations($sources);

        $normalizerRegistry = SourceNormalizerRegistry::create();
        $normalizerRegistry->register(new OpenApiSourceNormalizer(
            new OpenApiDocumentLoader($sources),
            new OpenApiVersionDetector(),
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        ));

        $loader = new SourceLoader(
            sources: $sources,
            versionDetector: new OpenApiVersionDetector(),
            normalizers: $normalizerRegistry,
        );
        $document = DocumentFacade::create();

        return new SourceRuntime(
            document: $document,
            loader: $loader,
            operations: $operations,
        );
    }

    /**
     * Generic façade only: the pure document pipeline.
     *
     * Registry-free by design — callers that need a particular registry use
     * `runtime()`, which threads one through both façades. A parameter here
     * would be silently ignored, so there is none.
     */
    public static function using(): DocumentInterface
    {
        return self::document();
    }

    /**
     * Build the concrete operation resolver for this slice.
     *
     * Public so a container can bind the resolver once and hand that same
     * instance to `runtime()`, instead of letting the graph build a second one
     * behind its back.
     */
    public static function operations(?SourceResolver $registry = null): OpenApiOperationResolver
    {
        return self::buildOperations($registry ?? self::defaultResolver());
    }

    private static function buildOperations(SourceResolver $sources): OpenApiOperationResolver
    {
        $detector = new OpenApiVersionDetector();

        return new OpenApiOperationResolver(
            new OpenApiDocumentLoader($sources),
            $detector,
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        );
    }
}
