<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\ResponseValidatorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Engine\Execution\SchemaValidator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class ResponseSchemaValidator implements ResponseValidatorInterface
{
    public function __construct(
        private OpenApiOperationResolver $operationResolver,
    ) {}

    public function validateResponseSchema(Step $step, int $statusCode, string $contentType, mixed $decodedBody, ?ArazzoDocument $document = null): void
    {
        $schema = $this->findResponseSchema($step, $statusCode, $contentType, $document);

        if ($schema === null) {
            return; // No schema to validate against, so it's "valid" by default
        }

        $violations = SchemaValidator::validate($schema, $decodedBody);

        if ($violations !== []) {
            throw SchemaValidationException::forStep($step->stepId, $violations);
        }
    }

    protected function findResponseSchema(Step $step, int $statusCode, string $contentType, ?ArazzoDocument $document = null): ?Schema
    {
        $operation = $this->findOperation($step, $document);
        $response = $operation !== null ? $this->resolveResponse($operation, $statusCode) : null;

        return $response !== null ? $this->extractMediaSchema($response, $contentType) : null;
    }

    private function resolveResponse(Operation $operation, int $statusCode): ?Response
    {
        if ($operation->responses === null) {
            return null;
        }

        $response = $operation->responses->getResponse((string) $statusCode)
            ?? $operation->responses->getResponse('default');

        if ($response instanceof Reference) {
            $response = $response->resolve();
        }

        return $response instanceof Response ? $response : null;
    }

    private function extractMediaSchema(Response $response, string $contentType): ?Schema
    {
        // OpenAPI content types often have charsets, e.g. "application/json; charset=utf-8"
        // We match strictly the base media type for simplicity here.
        $baseContentType = explode(';', $contentType)[0];

        $mediaType = $response->content[$baseContentType] ?? null;
        if ($mediaType === null) {
            return null;
        }

        $schema = $mediaType->schema;
        if ($schema instanceof Reference) {
            $schema = $schema->resolve();
        }

        return $schema instanceof Schema ? $schema : null;
    }

    protected function findOperation(Step $step, ?ArazzoDocument $document = null): ?Operation
    {
        if ($document === null) {
            return null;
        }

        try {
            return $this->operationResolver->resolve($step, $document)->cebeOperation;
        } catch (\RuntimeException) {
            return null;
        }
    }
}
