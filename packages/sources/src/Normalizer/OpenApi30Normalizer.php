<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Sources\Normalizer\Interfaces\OpenApiNormalizerInterface;
use InvalidArgumentException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class OpenApi30Normalizer implements OpenApiNormalizerInterface
{
    public function __construct(
        private readonly OpenApiParameterNormalizer $parameterNormalizer = new OpenApiParameterNormalizer(),
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function normalize(array $document, string $path, string $method): NormalizedOpenApiOperation
    {
        $method = strtolower($method);
        $pathItem = $this->requirePathItem($document, $path);
        $operation = $this->requireOperation($pathItem, $path, $method);

        $resolvedServerUrl = $this->resolveServerUrl($document, $pathItem, $operation);
        $parameters = $this->parameterNormalizer->resolveParameters($document, $pathItem, $operation);
        $requestBodies = $this->resolveRequestBodies($document, $operation);
        $responses = $this->resolveResponses($operation);

        return new NormalizedOpenApiOperation(
            path: $path,
            method: $method,
            resolvedServerUrl: $resolvedServerUrl,
            pathParameters: $parameters['path'],
            queryParameters: $parameters['query'],
            headerParameters: $parameters['header'],
            cookieParameters: $parameters['cookie'],
            requestBodies: $requestBodies,
            responses: $responses,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function requirePathItem(array $document, string $path): array
    {
        $paths = $document['paths'] ?? null;
        if (!is_array($paths) || !isset($paths[$path]) || !is_array($paths[$path])) {
            throw new InvalidArgumentException("Path '{$path}' not found in document.");
        }

        /** @var array<string, mixed> */
        return $paths[$path];
    }

    /**
     * @param  array<string, mixed>  $pathItem
     * @return array<string, mixed>
     */
    private function requireOperation(array $pathItem, string $path, string $method): array
    {
        if (!isset($pathItem[$method]) || !is_array($pathItem[$method])) {
            throw new InvalidArgumentException("Method '{$method}' not found for path '{$path}'.");
        }

        /** @var array<string, mixed> */
        return $pathItem[$method];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $pathItem
     * @param  array<string, mixed>  $operation
     */
    private function resolveServerUrl(array $document, array $pathItem, array $operation): ?string
    {
        foreach ([$operation, $pathItem, $document] as $scope) {
            if (isset($scope['servers']) && is_array($scope['servers']) && $scope['servers'] !== []) {
                return $this->firstServerUrl($scope['servers']);
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $servers
     */
    private function firstServerUrl(array $servers): ?string
    {
        $first = $servers[0] ?? null;

        return is_array($first) && is_string($first['url'] ?? null) ? $first['url'] : null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function resolveRequestBodies(array $document, array $operation): array
    {
        if (!isset($operation['requestBody']) || !is_array($operation['requestBody'])) {
            return [];
        }

        /** @var array<string, mixed> $reqBody */
        $reqBody = $operation['requestBody'];
        $requestBody = $this->parameterNormalizer->resolveLocalRef($document, $reqBody);

        if (!isset($requestBody['content']) || !is_array($requestBody['content'])) {
            return [];
        }

        $bodies = [];
        foreach ($requestBody['content'] as $mediaType => $mediaTypeObj) {
            $bodies[(string) $mediaType] = $mediaTypeObj;
        }

        return $bodies;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function resolveResponses(array $operation): array
    {
        if (!isset($operation['responses']) || !is_array($operation['responses'])) {
            return [];
        }

        $responses = [];
        foreach ($operation['responses'] as $status => $response) {
            $responses[(string) $status] = $response;
        }

        return $responses;
    }
}
