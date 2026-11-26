<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Exceptions\UnresolvableReferenceException;
use Alama\Arazzo\Sources\Resolver\Fetchers\DefaultFetchers;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceResolver;
use GuzzleHttp\Client;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class DefaultSourceResolver implements SourceResolver
{
    /**
     * Remote sources are advisory during validation (R5), so an unreachable
     * host must not stall the process until the caller's own timeout fires.
     */
    private const float HTTP_CONNECT_TIMEOUT = 2.0;

    private const float HTTP_REQUEST_TIMEOUT = 5.0;

    /** @var array<string, SourceDocument> */
    private array $seeded = [];

    /** @var array<string, SourceDocument> */
    private array $cache = [];

    /** @var array<string, true> */
    private array $resolving = [];

    private SourceIdentityCollection $identities;

    /**
     * @param  array<string, SourceFetcherInterface>  $fetchers
     * @param  array<string, SourceDocument>  $seeded
     */
    public function __construct(
        private readonly array $fetchers = [],
        private readonly ?DecodePolicy $decodePolicy = null,
        array $seeded = [],
        ?SourceIdentityCollection $identities = null,
    ) {
        $this->seeded = $seeded;

        if ($identities !== null) {
            $this->identities = $identities;
        } else {
            $collection = SourceIdentityCollection::empty();
            foreach ($seeded as $doc) {
                $collection = $collection->withDocument($doc);
            }
            $this->identities = $collection;
        }
    }

    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): self
    {
        $fetchers = $this->fetchers;
        $fetchers[strtolower($scheme)] = $fetcher;

        // Preserve explicitly seeded documents; clear fetched cache and dynamic identities so the new fetcher takes effect.
        return new self($fetchers, $this->decodePolicy, $this->seeded);
    }

    public function withDocument(SourceDocument $document): self
    {
        $seeded = $this->seeded;
        $seeded[$document->name] = $document;

        $identities = $this->identities->withDocument($document);

        return new self($this->fetchers, $this->decodePolicy, $seeded, $identities);
    }

    public function withDecodePolicy(?DecodePolicy $decodePolicy): self
    {
        return new self($this->fetchers, $decodePolicy, $this->seeded);
    }

    /**
     * The Guzzle client every default source fetch goes through.
     *
     * The explicit timeouts keep a dead remote source bounded to a few
     * seconds before the loader skips it; Guzzle's defaults wait forever.
     */
    public static function defaultHttpClient(
        float $connectTimeout = self::HTTP_CONNECT_TIMEOUT,
        float $timeout = self::HTTP_REQUEST_TIMEOUT,
    ): Client {
        return DefaultFetchers::httpClient($connectTimeout, $timeout);
    }

    /**
     * Create a resolver with the standard fetchers (http, https, file).
     * Uses Guzzle Client and PSR-7 HttpFactory internally.
     */
    public static function withDefaults(?DecodePolicy $decodePolicy = null): self
    {
        return new self(DefaultFetchers::create(), $decodePolicy);
    }

    /**
     * Create a resolver with custom fetchers.
     * Useful for testing or when different HTTP client configuration is needed.
     *
     * @param  array<string, SourceFetcherInterface>  $fetchers
     */
    public static function withFetchers(array $fetchers, ?DecodePolicy $decodePolicy = null): self
    {
        return new self($fetchers, $decodePolicy);
    }

    public function get(string $name): ?SourceDocument
    {
        return $this->seeded[$name] ?? $this->cache[$name] ?? null;
    }

    public function register(SourceDocument $document): void
    {
        $this->seeded[$document->name] = $document;
        $this->identities = $this->identities->withDocument($document);
    }

    public function identities(): SourceIdentityCollection
    {
        return $this->identities;
    }

    public function resolve(SourceDescription $source, string $basePath): SourceDocument
    {
        if (isset($this->seeded[$source->name])) {
            return $this->seeded[$source->name];
        }

        if (isset($this->cache[$source->name])) {
            return $this->cache[$source->name];
        }

        // Derive candidate target URI: resolve relative source URL against basePath
        $canonicalTarget = SourceIdentityCollection::resolveUri($source->url, $basePath);

        // Check identity collection: identity ($self) first, then retrieval location (Arazzo 1.1.0 §5.2, S2, S3)
        $knownDoc = $this->identities->findDocument($canonicalTarget) ?? $this->identities->findDocument($source->url);
        if ($knownDoc !== null) {
            $this->cache[$source->name] = $knownDoc;

            return $knownDoc;
        }

        if (isset($this->resolving[$source->name])) {
            throw UnresolvableReferenceException::circularReference($source->name);
        }

        $this->resolving[$source->name] = true;

        try {
            $document = $this->fetchAndDecode($source, $basePath, $canonicalTarget);
            $this->cache[$document->name] = $document;
            $this->identities = $this->identities->withDocument($document);

            return $document;
        } finally {
            unset($this->resolving[$source->name]);
        }
    }

    private function fetchAndDecode(SourceDescription $source, string $basePath, string $canonicalUri): SourceDocument
    {
        $scheme = parse_url($canonicalUri, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            $scheme = $source->scheme();
        } else {
            $scheme = strtolower($scheme);
        }

        $fetcher = $this->fetchers[$scheme] ?? null;
        if ($fetcher === null) {
            throw SourceFetchException::noFetcherForScheme($scheme);
        }

        $content = $fetcher->fetch($source->url, $basePath);
        $decoded = $this->decodeContent($content, $source->name);

        $type = $source->type ?? SourceDescription::inferFromContent($decoded);

        $selfUri = SourceIdentityCollection::extractSelfUri($decoded, $canonicalUri);

        return new SourceDocument(
            name: $source->name,
            type: $type,
            canonicalUri: $canonicalUri,
            content: $decoded,
            selfUri: $selfUri,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(string $content, string $sourceName): array
    {
        $format = str_starts_with(trim($content), '{') ? Format::Json : Format::Yaml;

        try {
            $decoded = Decoders::for($format, $this->decodePolicy)->decode($content);
        } catch (Throwable $e) {
            throw SourceParseException::parseFailed($sourceName, $e);
        }

        if (!is_array($decoded)) {
            throw SourceParseException::rootMustBeArray($sourceName);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
