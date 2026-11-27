<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Engine\Execution\ParameterSerializer;
use Alama\Arazzo\Engine\Execution\TypeCaster;
use Alama\Arazzo\Protocol\Http\Exceptions\HttpPolicyViolationException;
use Alama\Arazzo\Protocol\Http\Execution\Interfaces\OpenApiExecutorInterface;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Exception;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class DefaultOpenApiExecutor implements OpenApiExecutorInterface
{
    private readonly ParameterSerializer $parameterSerializer;

    private readonly TypeCaster $typeCaster;

    private readonly SourcePolicyApplicator $policyApplicator;

    private readonly LoggerInterface $logger;

    private readonly TracerInterface $tracer;

    private readonly HttpPolicy $policy;

    private OutboundRateLimiter $rateLimiter;

    private SafeHttpDispatcher $dispatcher;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        ?LoggerInterface $logger = null,
        ?TracerInterface $tracer = null,
        ?HttpPolicy $policy = null,
    ) {
        $this->parameterSerializer = new ParameterSerializer();
        $this->typeCaster = new TypeCaster();
        $this->policyApplicator = new SourcePolicyApplicator();
        $this->logger = $logger ?? new NullLogger();
        $this->tracer = $tracer ?? new NullTracer();
        $this->policy = $policy ?? HttpPolicy::default();
        $this->rateLimiter = new OutboundRateLimiter();
        $this->dispatcher = new SafeHttpDispatcher($this->httpClient);
    }

    public function withRateLimiter(OutboundRateLimiter $rateLimiter): self
    {
        $copy = clone $this;
        $copy->rateLimiter = $rateLimiter;

        return $copy;
    }

    public function withHostResolver(HostResolver $hostResolver): self
    {
        $copy = clone $this;
        $copy->dispatcher = new SafeHttpDispatcher(
            $this->httpClient,
            new OutboundSafetyGuard($hostResolver),
        );

        return $copy;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws \JsonException
     */
    public function execute(
        OpenApiOperationHandle $operation,
        OpenApiPayload $payload,
        ?callable $requestInterceptor = null,
        ?float $timeoutSeconds = null,
        ?SourcePolicy $sourcePolicy = null,
    ): ResponseInterface {
        $request = $this->buildRequest($operation, $payload, $sourcePolicy);
        $request = $this->policyApplicator->applyToRequest($request, $operation, $sourcePolicy);
        $credentialHeaders = $this->policyApplicator->injectedHeaderNames($operation, $sourcePolicy);

        if ($requestInterceptor !== null) {
            $intercepted = $requestInterceptor($request);
            $request = $intercepted instanceof RequestInterface ? $intercepted : $request;
        }

        $request = $this->injectTraceContext($request);
        $effectivePolicy = $this->policy->forSource($operation->source->name);

        return $this->dispatchRequest($request, $effectivePolicy, $timeoutSeconds, $credentialHeaders);
    }

    private function buildRequest(
        OpenApiOperationHandle $operation,
        OpenApiPayload $payload,
        ?SourcePolicy $sourcePolicy,
    ): RequestInterface {
        $normalized = $operation->operation->normalized;
        $baseUrl = $this->policyApplicator->resolveBaseUrl($operation->openApi, $sourcePolicy);
        $routed = $this->routeAutoParameters($normalized, $payload);

        $path = $this->castParameters($normalized->pathParameters, $routed['path']);
        $query = $this->castParameters($normalized->queryParameters, $routed['query']);
        $header = $this->castParameters($normalized->headerParameters, $routed['header']);
        $cookie = $this->castParameters($normalized->cookieParameters, $routed['cookie']);

        $url = $this->serializeUrl($baseUrl, $normalized, $path, $query);
        $request = $this->requestFactory->createRequest(strtoupper($normalized->method), $url);

        return $this->applyHeadersAndBody($request, $normalized, $header, $cookie, $payload);
    }

    /**
     * @return array{path: array<string, mixed>, query: array<string, mixed>, header: array<string, mixed>, cookie: array<string, mixed>}
     */
    private function routeAutoParameters(NormalizedOpenApiOperation $normalized, OpenApiPayload $payload): array
    {
        $path = $payload->path;
        $query = $payload->query;
        $header = $payload->header;
        $cookie = $payload->cookie;

        foreach ($payload->auto as $name => $value) {
            if (isset($normalized->pathParameters[$name])) {
                $path[$name] = $value;
            } elseif (isset($normalized->headerParameters[$name])) {
                $header[$name] = $value;
            } elseif (isset($normalized->cookieParameters[$name])) {
                $cookie[$name] = $value;
            } else {
                $query[$name] = $value;
            }
        }

        return ['path' => $path, 'query' => $query, 'header' => $header, 'cookie' => $cookie];
    }

    /**
     * @param  array<string, mixed>  $path
     * @param  array<string, mixed>  $query
     */
    private function serializeUrl(
        string $baseUrl,
        NormalizedOpenApiOperation $normalized,
        array $path,
        array $query,
    ): string {
        $urlPath = $normalized->path;

        $serializedPath = $this->parameterSerializer->serialize('path', $normalized->pathParameters, $path);
        foreach ($serializedPath as $name => $value) {
            $style = $normalized->pathParameters[$name]['style'] ?? 'simple';
            $replacement = $style === 'simple' ? urlencode($value) : $value;
            $urlPath = str_replace('{'.$name.'}', $replacement, $urlPath);
        }

        $url = $baseUrl.$urlPath;

        $serializedQuery = $this->parameterSerializer->serialize('query', $normalized->queryParameters, $query);
        $filteredQuery = array_filter($serializedQuery, static fn ($val): bool => $val !== '');
        if ($filteredQuery !== []) {
            $url .= '?'.implode('&', array_values($filteredQuery));
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $cookie
     */
    private function applyHeadersAndBody(
        RequestInterface $request,
        NormalizedOpenApiOperation $normalized,
        array $header,
        array $cookie,
        OpenApiPayload $payload,
    ): RequestInterface {
        $serializedHeader = $this->parameterSerializer->serialize('header', $normalized->headerParameters, $header);
        foreach ($serializedHeader as $k => $v) {
            $request = $request->withHeader($k, (string) $v);
        }

        $serializedCookie = $this->parameterSerializer->serialize('cookie', $normalized->cookieParameters, $cookie);
        if ($serializedCookie !== []) {
            $request = $request->withHeader('Cookie', implode('; ', array_values($serializedCookie)));
        }

        if ($payload->body !== null) {
            $mediaType = $payload->bodyMediaType ?? 'application/json';
            $request = $request->withHeader('Content-Type', $mediaType);

            $bodyStream = $mediaType === 'application/json'
                ? json_encode($payload->body, JSON_THROW_ON_ERROR)
                : (is_scalar($payload->body) ? (string) $payload->body : http_build_query((array) $payload->body));
            $request = $request->withBody(Utils::streamFor($bodyStream));
        }

        return $request;
    }

    private function injectTraceContext(RequestInterface $request): RequestInterface
    {
        $carrier = [];
        $this->tracer->injectContext($carrier);

        foreach ($carrier as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        return $request;
    }

    /**
     * @param  list<string>  $credentialHeaders
     */
    private function dispatchRequest(
        RequestInterface $request,
        HttpPolicy $effectivePolicy,
        ?float $timeoutSeconds,
        array $credentialHeaders,
    ): ResponseInterface {
        $span = $this->tracer->startSpan('arazzo.http.request', [
            'http.request.method' => $request->getMethod(),
            'url.full' => (string) $request->getUri(),
        ]);

        $targetKey = $this->rateLimiter->resolveTargetKey($request, $effectivePolicy->rateLimit->scope);
        $acquired = false;

        try {
            $this->rateLimiter->acquire($targetKey, $effectivePolicy->rateLimit, $this->logger, $span);
            $acquired = true;

            $response = $this->dispatcher->dispatch($request, $effectivePolicy->safety, $timeoutSeconds, $credentialHeaders);
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            $span->setStatus(true);

            $this->logger->debug('Executed HTTP operation.', [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'status' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (Throwable $e) {
            if ($e instanceof HttpPolicyViolationException) {
                $context = [
                    'code' => $e->getErrorCode(),
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                    'message' => $e->getMessage(),
                ];
                $this->logger->warning($e->getErrorCode(), $context);
                $span->addEvent($e->getErrorCode(), $context);
            }

            $span->recordException($e);
            $span->setStatus(false, $e->getMessage());

            $this->logger->warning('HTTP operation failed.', [
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($acquired) {
                $this->rateLimiter->release($targetKey);
            }
            $span->end();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $normalizedParams
     * @param  array<string, mixed>  $payloadParams
     * @return array<string, mixed>
     */
    private function castParameters(array $normalizedParams, array $payloadParams): array
    {
        $result = [];
        foreach ($payloadParams as $name => $value) {
            /** @var array<string, mixed>|null $schema */
            $schema = $normalizedParams[$name]['schema'] ?? null;
            $result[$name] = $this->castToSchemaType($value, $schema);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function castToSchemaType(mixed $value, ?array $schema): mixed
    {
        // A JSON Schema may declare a type list (`["string", "null"]`); only a
        // single named type can drive a cast.
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            return $value;
        }

        try {
            return match ($type) {
                'integer' => $this->typeCaster->asInteger($value),
                'number' => $this->typeCaster->asFloat($value),
                'string' => $this->typeCaster->asString($value),
                'boolean' => $this->typeCaster->asBoolean($value),
                'array' => $this->typeCaster->asArray($value),
                default => $value,
            };
        } catch (Exception $e) {
            $this->logger->warning("Failed to cast value to schema type '{$type}': {$e->getMessage()}");

            return $value;
        }
    }
}
