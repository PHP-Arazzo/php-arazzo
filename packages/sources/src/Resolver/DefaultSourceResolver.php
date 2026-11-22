<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders;
use Alama\Arazzo\Sources\Resolver\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Resolver\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Resolver\Exceptions\UnresolvableReferenceException;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceFetcher;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
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
    private array $documents = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct(
        /** @var array<string, SourceFetcher> */
        private array $fetchers = [],
        private ?DecodePolicy $decodePolicy = null,
    ) {}

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
        $factory = new HttpFactory();

        return new self([
            'http' => new HttpFetcher($client, $factory),
            'https' => new HttpFetcher($client, $factory),
            'file' => new LocalFetcher(),
        ], $decodePolicy);
    }

    /**
     * Create a resolver with custom fetchers.
     * Useful for testing or when different HTTP client configuration is needed.
     *
     * @param  array<string, SourceFetcher>  $fetchers
     */
    public static function withFetchers(array $fetchers, ?DecodePolicy $decodePolicy = null): self
    {
        return new self($fetchers, $decodePolicy);
    }

    public function get(string $name): ?SourceDocument
    {
        return $this->documents[$name] ?? null;
    }

    public function register(SourceDocument $document): void
    {
        $this->documents[$document->name] = $document;
    }

    public function resolve(SourceDescription $source, string $basePath): SourceDocument
    {
        if (isset($this->documents[$source->name])) {
            return $this->documents[$source->name];
        }

        if (isset($this->resolving[$source->name])) {
            throw new UnresolvableReferenceException(
                "Circular reference detected when resolving source '{$source->name}'",
                $source->name,
            );
        }

        $this->resolving[$source->name] = true;

        try {
            $scheme = parse_url($source->url, PHP_URL_SCHEME);
            if (!is_string($scheme) || $scheme === '') {
                $scheme = 'file';
            }

            $fetcher = $this->fetchers[$scheme] ?? null;
            if ($fetcher === null) {
                throw new SourceFetchException("No fetcher configured for scheme '{$scheme}'.");
            }

            $content = $fetcher->fetch($source->url, $basePath);

            $isYaml = !str_starts_with(trim($content), '{');
            try {
                $decoded = $isYaml
                    ? Decoders::for(Format::Yaml, $this->decodePolicy)->decode($content)
                    : Decoders::for(Format::Json, $this->decodePolicy)->decode($content);
            } catch (Throwable $e) {
                throw new SourceParseException("Failed to parse source '{$source->name}': ".$e->getMessage(), 0, $e);
            }

            if (!is_array($decoded)) {
                throw new SourceParseException("Parsed document for '{$source->name}' must be an array.");
            }

            // Canonical URI determination can be simple for now.
            // If it's a file scheme, and relative, it should be resolved against basePath.
            $canonicalUri = $this->resolveCanonicalUri($source->url, $basePath);

            /** @var array<string, mixed> $decoded */
            $document = new SourceDocument(
                name: $source->name,
                type: $source->type,
                canonicalUri: $canonicalUri,
                content: $decoded,
            );

            $this->register($document);

            return $document;
        } finally {
            unset($this->resolving[$source->name]);
        }
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
