<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Data\SourceInspectionResult;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

interface SourcesInterface extends SourceResolverInterface
{
    /**
     * Resolve a single source description into a decoded document.
     *
     * @throws SourceResolutionException
     */
    public function resolve(SourceDescription $source, string $basePath = ''): SourceDocument;

    /**
     * Resolve and normalize all sources declared by an ArazzoDocument.
     * Returns a typed, keyed collection of resolved operations.
     *
     * @throws SourceResolutionException
     */
    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection;

    /**
     * Return known operations suitable for document validation (Rule 03).
     *
     * @throws SourceResolutionException
     */
    public function knownOperations(ArazzoDocument $document, string $basePath = ''): KnownOperations;

    /**
     * Inspect all source descriptions for reachability, parseability, and type conformance under resolve mode.
     */
    public function inspectSources(ArazzoDocument $document, string $basePath = ''): SourceInspectionResult;

    /**
     * Immutably configure the fetch policy (timeouts, byte limits, allowed schemes, strict reachability).
     */
    public function withPolicy(FetchPolicy $policy): static;

    /**
     * Immutably attach a PSR-3 logger for structured source resolution diagnostics.
     */
    public function withLogger(LoggerInterface $logger): static;

    /**
     * Immutably attach a vendor-agnostic tracer for source fetch spans and W3C context injection.
     */
    public function withTracer(TracerInterface $tracer): static;

    /**
     * Immutably rebind the HTTP client used for remote fetching.
     */
    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): static;

    /**
     * Immutably register a custom source fetcher for a given protocol/scheme.
     */
    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): static;

    /**
     * Immutably register a custom source normalizer for non-standard formats.
     */
    public function withNormalizer(SourceNormalizerInterface $normalizer): static;

    /**
     * Immutably seed an in-memory SourceDocument so resolve() returns it directly.
     */
    public function withDocument(SourceDocument $document): static;
}
