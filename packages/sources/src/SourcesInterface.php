<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

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
}
