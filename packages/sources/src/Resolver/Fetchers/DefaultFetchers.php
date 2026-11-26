<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver\Fetchers;

use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use GuzzleHttp\Client;

/**
 * Factory for standard default fetchers (http, https, file).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class DefaultFetchers
{
    private const float HTTP_CONNECT_TIMEOUT = 2.0;

    private const float HTTP_REQUEST_TIMEOUT = 5.0;

    public static function httpClient(
        float $connectTimeout = self::HTTP_CONNECT_TIMEOUT,
        float $timeout = self::HTTP_REQUEST_TIMEOUT,
    ): Client {
        return new Client([
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ]);
    }

    /**
     * @return array<string, SourceFetcherInterface>
     */
    public static function create(?Client $client = null): array
    {
        $client ??= self::httpClient();

        return [
            'http' => new HttpFetcher($client),
            'https' => new HttpFetcher($client),
            'file' => new LocalFetcher(),
        ];
    }
}
