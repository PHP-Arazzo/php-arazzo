<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver\Fetchers;

use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Resolver\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceFetcher;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class HttpFetcher implements SourceFetcher
{
    private readonly RequestFactoryInterface $requestFactory;

    private readonly FetchPolicy $policy;

    private readonly LoggerInterface $logger;

    private readonly TracerInterface $tracer;

    public function __construct(
        private readonly ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?FetchPolicy $policy = null,
        ?LoggerInterface $logger = null,
        ?TracerInterface $tracer = null,
    ) {
        $this->requestFactory = $requestFactory ?? new HttpFactory();
        $this->policy = $policy ?? FetchPolicy::default();
        $this->logger = $logger ?? new NullLogger();
        $this->tracer = $tracer ?? new NullTracer();
    }

    public function fetch(string $urlOrPath, string $basePath): string
    {
        $url = self::resolveUrl($urlOrPath, $basePath);
        $span = $this->tracer->startSpan('arazzo.source.fetch', ['url.full' => $url]);

        try {
            $request = $this->requestFactory->createRequest('GET', $url);
            $carrier = [];
            $this->tracer->injectContext($carrier);
            foreach ($carrier as $header => $value) {
                $request = $request->withHeader($header, $value);
            }

            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $span->recordException($e);
                $span->setStatus(false, $e->getMessage());
                $this->logger->warning('HTTP connection failed while fetching source.', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                throw SourceFetchException::httpConnectionFailed($url, $e);
            }

            $statusCode = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $statusCode);

            if ($statusCode < 200 || $statusCode >= 300) {
                $exception = SourceFetchException::httpErrorStatus($url, $statusCode);
                $span->setStatus(false, $exception->getMessage());
                $this->logger->warning('HTTP request failed while fetching source.', [
                    'url' => $url,
                    'status' => $statusCode,
                ]);

                throw $exception;
            }

            $body = (string) $response->getBody();
            $byteLength = strlen($body);
            $span->setAttribute('http.response.body.size', $byteLength);

            if ($byteLength > $this->policy->maxBytes) {
                $exception = SourceFetchException::responseTooLarge($url, $this->policy->maxBytes);
                $span->setStatus(false, $exception->getMessage());
                $this->logger->warning('HTTP source response exceeded FetchPolicy::maxBytes.', [
                    'url' => $url,
                    'bytes' => $byteLength,
                    'max_bytes' => $this->policy->maxBytes,
                ]);

                throw $exception;
            }

            $span->setStatus(true);
            $this->logger->debug('Fetched remote source.', [
                'url' => $url,
                'status' => $statusCode,
                'bytes' => $byteLength,
            ]);

            return $body;
        } finally {
            $span->end();
        }
    }

    private static function resolveUrl(string $urlOrPath, string $basePath): string
    {
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            return $urlOrPath;
        }

        if (str_starts_with($basePath, 'http://') || str_starts_with($basePath, 'https://')) {
            return rtrim($basePath, '/').'/'.ltrim($urlOrPath, '/');
        }

        throw SourceFetchException::relativeUrlRequiresHttpBase($urlOrPath);
    }
}
