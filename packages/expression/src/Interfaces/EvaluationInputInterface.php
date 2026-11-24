<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Interfaces;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;

interface EvaluationInputInterface
{
    public function getWorkflowContext(): WorkflowContextInterface;

    public function getCurrentStepId(): ?string;

    public function getDocument(): ?ArazzoDocument;

    /**
     * Default expression root when a selector omits its context: the current
     * step's response body, or an empty array when unavailable.
     *
     * @return array<mixed, mixed>
     */
    public function defaultRootBody(): array;

    /** @return array<string, mixed>|null */
    public function stepResponse(string $stepId): ?array;

    /** @return array<string, mixed>|null */
    public function stepRequest(string $stepId): ?array;

    /** @return array<string, mixed> */
    public function responseBodyForStep(string $stepId): array;

    /** @return array<string, mixed> */
    public function requestBodyForStep(string $stepId): array;

    /**
     * HTTP metadata for a step: statusCode, method, or url. Null when
     * unavailable.
     */
    public function httpMetaForStep(string $stepId, string $field): mixed;

    /** @return array<string, mixed> */
    public function stepOutputs(string $stepId): array;

    /** @return array<string, mixed> */
    public function stepInputs(string $stepId): array;

    /**
     * Case-insensitive header lookup on a step's response. Null when
     * unavailable.
     */
    public function stepResponseHeader(string $stepId, string $name): ?string;
}
