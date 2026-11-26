<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\RequestValidatorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Engine\Execution\SchemaCompositionValidator;
use Alama\Arazzo\Engine\Execution\SchemaValidator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;

/**
 * Validates outbound request bodies and parameters against the OpenAPI operation schema.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class RequestSchemaValidator implements RequestValidatorInterface
{
    public function __construct(
        private ?OpenApiOperationResolver $operationResolver = null,
    ) {}

    public function validateRequest(
        Step $step,
        ResolvedOperation $operation,
        OpenApiPayload $payload,
        ?ArazzoDocument $document = null,
    ): void {
        $operationHandle = $this->resolveOperationHandle($step, $operation, $document);
        $cebeOperation = $operationHandle?->cebeOperation;

        if ($cebeOperation === null) {
            return;
        }

        $violations = [
            ...$this->validateParameters($cebeOperation, $operationHandle, $payload),
            ...$this->validateRequestBody($cebeOperation, $payload),
        ];

        if ($violations !== []) {
            throw SchemaValidationException::forRequestStep($step->stepId, $violations);
        }
    }

    private function resolveOperationHandle(
        Step $step,
        ResolvedOperation $operation,
        ?ArazzoDocument $document = null,
    ): ?OpenApiOperationHandle {
        if ($operation instanceof OpenApiOperationHandle) {
            return $operation;
        }

        if ($this->operationResolver !== null && $document !== null) {
            try {
                return $this->operationResolver->resolve($step, $document);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateParameters(
        Operation $cebeOperation,
        OpenApiOperationHandle $operationHandle,
        OpenApiPayload $payload,
    ): array {
        $violations = [];
        $parameters = $this->collectParameters($cebeOperation, $operationHandle);

        foreach ($parameters as $param) {
            $violations = [...$violations, ...$this->validateSingleParameter($param, $payload)];
        }

        return $violations;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateSingleParameter(Parameter $param, OpenApiPayload $payload): array
    {
        $in = $param->in;
        $name = $param->name;
        $pointer = "/parameters/{$in}/{$name}";

        [$hasValue, $value] = $this->lookupParameterValue($in, $name, $payload);
        $paramSchema = $this->resolveSchema($param->schema);

        if ($param->required === true && (!$hasValue || $value === null)) {
            $isNullable = $paramSchema !== null && ($paramSchema->nullable === true || ($paramSchema->type ?? null) === null);
            if (!$hasValue || !$isNullable) {
                return [[
                    'path' => $pointer,
                    'message' => "missing required {$in} parameter '{$name}'",
                ]];
            }
        }

        if (!$hasValue || $paramSchema === null) {
            return [];
        }

        return SchemaValidator::validate($paramSchema, $value, $pointer);
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function lookupParameterValue(string $in, string $name, OpenApiPayload $payload): array
    {
        $bucket = match ($in) {
            'path' => $payload->path,
            'query' => $payload->query,
            'header' => $payload->header,
            'cookie' => $payload->cookie,
            default => null,
        };

        if ($bucket === null) {
            return [false, null];
        }

        if (array_key_exists($name, $bucket)) {
            return [true, $bucket[$name]];
        }

        if ($in === 'header') {
            foreach ($bucket as $headerName => $val) {
                if (strcasecmp($headerName, $name) === 0) {
                    return [true, $val];
                }
            }
        }

        return [false, null];
    }

    /**
     * @return array<string, Parameter>
     */
    private function collectParameters(
        Operation $cebeOperation,
        OpenApiOperationHandle $operationHandle,
    ): array {
        $parameters = [];
        $this->collectPathItemParameters($operationHandle, $parameters);
        $this->collectOperationParameters($cebeOperation, $parameters);

        return $parameters;
    }

    /**
     * @param  array<string, Parameter>  $parameters
     */
    private function collectPathItemParameters(
        OpenApiOperationHandle $operationHandle,
        array &$parameters,
    ): void {
        $path = $operationHandle->normalized->path;
        $pathItem = $operationHandle->openApi->paths[$path] ?? null;

        if ($pathItem === null) {
            return;
        }

        foreach ($pathItem->parameters ?? [] as $param) {
            if ($param instanceof Reference) {
                $param = $param->resolve();
            }
            if ($param instanceof Parameter) {
                $parameters[$param->in.':'.$param->name] = $param;
            }
        }
    }

    /**
     * @param  array<string, Parameter>  $parameters
     */
    private function collectOperationParameters(
        Operation $cebeOperation,
        array &$parameters,
    ): void {
        foreach ($cebeOperation->parameters ?? [] as $param) {
            if ($param instanceof Reference) {
                $param = $param->resolve();
            }
            if ($param instanceof Parameter) {
                $parameters[$param->in.':'.$param->name] = $param;
            }
        }
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateRequestBody(
        Operation $cebeOperation,
        OpenApiPayload $payload,
    ): array {
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

        $schema = $this->resolveSchema($mediaType->schema);
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

    private function resolveSchema(mixed $schemaOrRef): ?Schema
    {
        return SchemaCompositionValidator::resolveSchema($schemaOrRef);
    }
}
