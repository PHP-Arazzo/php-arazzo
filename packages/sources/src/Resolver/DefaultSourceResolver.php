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
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
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
    private const HTTP_CONNECT_TIMEOUT = 2.0;

    private const HTTP_REQUEST_TIMEOUT = 5.0;

    /** @var array<string, SourceDocument> */
    private array $seeded = [];

    /** @var array<string, SourceDocument> */
    private array $cache = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * @param  array<string, SourceFetcherInterface>  $fetchers
     * @param  array<string, SourceDocument>  $seeded
     */
    public function __construct(
        private readonly array $fetchers = [],
        private readonly ?DecodePolicy $decodePolicy = null,
        array $seeded = [],
    ) {
        $this->seeded = $seeded;
    }

    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): self
    {
        $fetchers = $this->fetchers;
        $fetchers[$scheme] = $fetcher;

        // Preserve explicitly seeded documents; clear fetched cache so the new fetcher takes effect.
        return new self($fetchers, $this->decodePolicy, $this->seeded);
    }

    public function withDocument(SourceDocument $document): self
    {
        $seeded = $this->seeded;
        $seeded[$document->name] = $document;

        return new self($this->fetchers, $this->decodePolicy, $seeded);
    }

    /**
     * The Guzzle client every default source fetch goes through.
     *
     * The explicit timeouts keep a dead remote source bounded to a few
     * seconds before the loader skips it; Guzzle's defaults wait forever.
     */
    public static function defaultHttpClient(): Client
    {
        return new Client([
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            'timeout' => self::HTTP_REQUEST_TIMEOUT,
        ]);
    }

    /**
     * Create a resolver with the standard fetchers (http, https, file).
     * Uses Guzzle Client and PSR-7 HttpFactory internally.
     */
    public static function withDefaults(?DecodePolicy $decodePolicy = null): self
    {
        $client = self::defaultHttpClient();

        return new self([
            'http' => new HttpFetcher($client),
            'https' => new HttpFetcher($client),
            'file' => new LocalFetcher(),
        ], $decodePolicy);
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
    }

    public function resolve(SourceDescription $source, string $basePath): SourceDocument
    {
        if (isset($this->seeded[$source->name])) {
            return $this->seeded[$source->name];
        }

        if (isset($this->cache[$source->name])) {
            return $this->cache[$source->name];
        }

        if (isset($this->resolving[$source->name])) {
            throw new UnresolvableReferenceException(
                "Circular reference detected when resolving source '{$source->name}'",
                $source->name,
            );
        }

        $this->resolving[$source->name] = true;

        try {
            $document = $this->fetchAndDecode($source, $basePath);
            $this->cache[$document->name] = $document;

            return $document;
        } finally {
            unset($this->resolving[$source->name]);
        }
    }

    private function fetchAndDecode(SourceDescription $source, string $basePath): SourceDocument
    {
        $scheme = parse_url($source->url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            $scheme = 'file';
        }

        $fetcher = $this->fetchers[$scheme] ?? null;
        if ($fetcher === null) {
            throw new SourceFetchException("No fetcher configured for scheme '{$scheme}'.");
        }

        $content = $fetcher->fetch($source->url, $basePath);
        $decoded = $this->decodeContent($content, $source->name);

        return new SourceDocument(
            name: $source->name,
            type: $source->type,
            canonicalUri: $this->resolveCanonicalUri($source->url, $basePath),
            content: $decoded,
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
            throw new SourceParseException("Failed to parse source '{$sourceName}': ".$e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new SourceParseException("Parsed document for '{$sourceName}' must be an array.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function resolveCanonicalUri(string $url, string $basePath): string
    {
        $url = str_replace('\\', '/', $url);
        $basePath = str_replace('\\', '/', $basePath);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            return $url; // Already absolute with scheme
        }

        // Relative file path
        if (str_starts_with($url, '/')) {
            return 'file://'.$url;
        }

        $base = rtrim($basePath, '/');

        // Let's do basic relative path resolution
        $path = $base.'/'.$url;

        // Resolve . and ..
        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return 'file:///'.implode('/', $resolved);
    }
}
