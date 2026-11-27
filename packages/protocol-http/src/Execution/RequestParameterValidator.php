<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Engine\Execution\SchemaCompositionValidator;
use Alama\Arazzo\Engine\Execution\SchemaValidator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

/**
 * Validates outbound request parameters against OpenAPI parameter schemas.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class RequestParameterValidator
{
    private ParameterRouter $parameterRouter;

    public function __construct(?ParameterRouter $parameterRouter = null)
    {
        $this->parameterRouter = $parameterRouter ?? new ParameterRouter();
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function validate(
        Operation $cebeOperation,
        OpenApiOperationHandle $operationHandle,
        OpenApiPayload $payload,
    ): array {
        $payload = $this->parameterRouter->routeAndCast($operationHandle->normalized, $payload);
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

    private function resolveSchema(mixed $schemaOrRef): ?Schema
    {
        return SchemaCompositionValidator::resolveSchema($schemaOrRef);
    }
}
