<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\RequestValidatorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;

/**
 * Validates outbound request bodies and parameters against the OpenAPI operation schema.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class RequestSchemaValidator implements RequestValidatorInterface
{
    private RequestParameterValidator $parameterValidator;

    private RequestBodyValidator $bodyValidator;

    public function __construct(
        private ?OpenApiOperationResolver $operationResolver = null,
        ?RequestParameterValidator $parameterValidator = null,
        ?RequestBodyValidator $bodyValidator = null,
    ) {
        $this->parameterValidator = $parameterValidator ?? new RequestParameterValidator();
        $this->bodyValidator = $bodyValidator ?? new RequestBodyValidator();
    }

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
            ...$this->parameterValidator->validate($cebeOperation, $operationHandle, $payload),
            ...$this->bodyValidator->validate($cebeOperation, $payload),
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
}
