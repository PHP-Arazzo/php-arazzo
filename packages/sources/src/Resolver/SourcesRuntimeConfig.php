<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Internal immutable runtime configuration carried by Sources across fluent rebinds.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class SourcesRuntimeConfig
{
    public LoggerInterface $logger;

    public TracerInterface $tracer;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?TracerInterface $tracer = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public bool $customHttpFetcher = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->tracer = $tracer ?? new NullTracer();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            logger: $logger,
            tracer: $this->tracer,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            customHttpFetcher: $this->customHttpFetcher,
        );
    }

    public function withTracer(TracerInterface $tracer): self
    {
        return new self(
            logger: $this->logger,
            tracer: $tracer,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            customHttpFetcher: $this->customHttpFetcher,
        );
    }

    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): self
    {
        return new self(
            logger: $this->logger,
            tracer: $this->tracer,
            httpClient: $client,
            requestFactory: $requestFactory,
            customHttpFetcher: false,
        );
    }

    public function withCustomHttpFetcher(): self
    {
        return new self(
            logger: $this->logger,
            tracer: $this->tracer,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            customHttpFetcher: true,
        );
    }
}
