<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Data;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;

final readonly class EvaluationContext implements EvaluationInputInterface
{
    public function __construct(
        public WorkflowContextInterface $workflowContext,
        public ?string $currentStepId = null,
        public ?ArazzoDocument $document = null,
    ) {}

    public function getWorkflowContext(): WorkflowContextInterface
    {
        return $this->workflowContext;
    }

    public function getCurrentStepId(): ?string
    {
        return $this->currentStepId;
    }

    public function getDocument(): ?ArazzoDocument
    {
        return $this->document;
    }

    /**
     * Default expression root when a selector omits its context: the current
     * step's response body, or an empty array when unavailable.
     *
     * Encapsulates the step-record array shape so callers do not reach into
     * getSteps()[$stepId]['response']['body'] themselves (Tell, Don't Ask).
     *
     * @return array<mixed, mixed>
     */
    public function defaultRootBody(): array
    {
        if ($this->currentStepId === null) {
            return [];
        }

        $response = $this->workflowContext->getStepResponse($this->currentStepId);
        if ($response === null) {
            return [];
        }

        $body = $response['body'] ?? null;

        return is_array($body) ? $body : [];
    }

    /** @return array<mixed, mixed>|null */
    public function stepResponse(string $stepId): ?array
    {
        return $this->workflowContext->getStepResponse($stepId);
    }

    /** @return array<mixed, mixed>|null */
    public function stepRequest(string $stepId): ?array
    {
        return $this->workflowContext->getStepRequest($stepId);
    }

    /** @return array<mixed, mixed> */
    public function responseBodyForStep(string $stepId): array
    {
        $response = $this->stepResponse($stepId);
        if ($response === null) {
            return [];
        }

        $body = $response['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }

        return $body;
    }

    /** @return array<mixed, mixed> */
    public function requestBodyForStep(string $stepId): array
    {
        $request = $this->stepRequest($stepId);
        if ($request === null) {
            return [];
        }

        $body = $request['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }

        return $body;
    }

    /**
     * HTTP metadata for a step: statusCode, method, or url. Returns null when
     * the step or the requested field is unavailable.
     */
    public function httpMetaForStep(string $stepId, string $field): mixed
    {
        $stepData = $this->workflowContext->getStepRecord($stepId);
        if ($stepData === null) {
            return null;
        }

        return match ($field) {
            'statusCode' => $this->responseField($stepData, 'statusCode'),
            'method' => $this->requestField($stepData, 'method'),
            'url' => $this->requestField($stepData, 'url'),
            default => null,
        };
    }

    /** @return array<mixed, mixed> */
    public function stepOutputs(string $stepId): array
    {
        return $this->workflowContext->getStepOutputs($stepId);
    }

    /** @return array<mixed, mixed> */
    public function stepInputs(string $stepId): array
    {
        return $this->workflowContext->getStepInputs($stepId);
    }

    /**
     * Case-insensitive header lookup on a step's response. Returns null when
     * unavailable.
     */
    public function stepResponseHeader(string $stepId, string $name): ?string
    {
        $response = $this->stepResponse($stepId);
        if ($response === null) {
            return null;
        }

        $headers = $response['headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0 && is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<mixed, mixed> $stepData */
    private function responseField(array $stepData, string $field): mixed
    {
        $response = $stepData['response'] ?? null;
        if (!is_array($response)) {
            return null;
        }

        return $response[$field] ?? null;
    }

    /** @param array<mixed, mixed> $stepData */
    private function requestField(array $stepData, string $field): mixed
    {
        $request = $stepData['request'] ?? null;
        if (!is_array($request)) {
            return null;
        }

        return $request[$field] ?? null;
    }
}
