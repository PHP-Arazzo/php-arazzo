<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Engine\Execution\TypeCaster;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Routes auto-parameters into concrete location buckets (path, query, header, cookie)
 * and casts parameter values to declared OpenAPI schema scalar types.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class ParameterRouter
{
    private TypeCaster $typeCaster;

    private LoggerInterface $logger;

    public function __construct(
        ?TypeCaster $typeCaster = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->typeCaster = $typeCaster ?? new TypeCaster();
        $this->logger = $logger ?? new NullLogger();
    }

    public function routeAndCast(NormalizedOpenApiOperation $normalized, OpenApiPayload $payload): OpenApiPayload
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

        $path = $this->castParameters($normalized->pathParameters, $path);
        $query = $this->castParameters($normalized->queryParameters, $query);
        $header = $this->castParameters($normalized->headerParameters, $header);
        $cookie = $this->castParameters($normalized->cookieParameters, $cookie);

        return $payload->withRouted($path, $query, $header, $cookie);
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
