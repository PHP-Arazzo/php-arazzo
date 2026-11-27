<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

readonly class NormalizedOpenApiOperation
{
    /**
     * @param  array<string, array<string, mixed>>  $pathParameters
     * @param  array<string, array<string, mixed>>  $queryParameters
     * @param  array<string, array<string, mixed>>  $headerParameters
     * @param  array<string, array<string, mixed>>  $cookieParameters
     * @param  array<string, mixed>  $requestBodies
     * @param  array<string, mixed>  $responses
     */
    public function __construct(
        public string $path,
        public string $method,
        public ?string $resolvedServerUrl = null,
        public array $pathParameters = [],
        public array $queryParameters = [],
        public array $headerParameters = [],
        public array $cookieParameters = [],
        public array $requestBodies = [],
        public array $responses = [],
    ) {}
}
