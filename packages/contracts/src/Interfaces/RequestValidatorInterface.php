<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Step;

interface RequestValidatorInterface
{
    /**
     * Validates an outbound request payload against the step's OpenAPI operation schema.
     * Throws SchemaValidationException if validation fails.
     * Does nothing if no matching schema is found.
     *
     * @throws SchemaValidationException
     */
    public function validateRequest(
        Step $step,
        ResolvedOperation $operation,
        OpenApiPayload $payload,
        ?ArazzoDocument $document = null,
    ): void;
}
