<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Engine\Execution\SchemaCompositionValidator;
use Alama\Arazzo\Engine\Execution\SchemaValidator;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;

/**
 * Validates outbound request bodies against OpenAPI requestBody schemas.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class RequestBodyValidator
{
    /**
     * @return list<array{path: string, message: string}>
     */
    public function validate(Operation $cebeOperation, OpenApiPayload $payload): array
    {
        $requestBody = $this->resolveRequestBody($cebeOperation);
        if ($requestBody === null) {
            return [];
        }

        if ($requestBody->required === true && $payload->body === null) {
            return [[
                'path' => '/body',
                'message' => 'request body is required',
            ]];
        }

        if ($payload->body === null) {
            return [];
        }

        return $this->validateRequestBodyContent($requestBody, $payload);
    }

    private function resolveRequestBody(Operation $cebeOperation): ?RequestBody
    {
        $requestBody = $cebeOperation->requestBody;
        if ($requestBody instanceof Reference) {
            $requestBody = $requestBody->resolve();
        }

        return $requestBody instanceof RequestBody ? $requestBody : null;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateRequestBodyContent(
        RequestBody $requestBody,
        OpenApiPayload $payload,
    ): array {
        $content = $requestBody->content ?? [];
        if ($content === []) {
            return [];
        }

        [$mediaType, $error] = $this->matchMediaType($content, $payload->bodyMediaType);
        if ($error !== null) {
            return [[
                'path' => '/body',
                'message' => $error,
            ]];
        }

        if ($mediaType === null) {
            return [];
        }

        $schema = SchemaCompositionValidator::resolveSchema($mediaType->schema);
        if ($schema === null) {
            return [];
        }

        return SchemaValidator::validate($schema, $payload->body, '/body');
    }

    /**
     * @param  array<array-key, MediaType>  $content
     * @return array{0: ?MediaType, 1: ?string}
     */
    private function matchMediaType(array $content, ?string $declaredType): array
    {
        if ($declaredType !== null) {
            $baseType = explode(';', $declaredType)[0];
            $matched = $content[$baseType] ?? $content[$declaredType] ?? null;

            if ($matched === null) {
                return [null, "content type '{$declaredType}' is not supported by the operation"];
            }

            return [$matched, null];
        }

        $matched = $content['application/json'] ?? null;
        if ($matched === null) {
            $firstKey = array_key_first($content);
            $matched = $firstKey !== null ? $content[$firstKey] : null;
        }

        return [$matched, null];
    }
}
