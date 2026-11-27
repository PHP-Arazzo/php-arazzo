<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Engine\Execution\RequestCompiler;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal step request/response compilation and per-source {@see HttpPolicy}
 * resolution collaborator for {@see HttpStepExecutor}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class HttpStepPipeline
{
    private RequestCompiler $compiler;

    public function __construct(
        EvaluationEngineInterface $evaluationEngine,
        private ?HttpPolicy $policy = null,
    ) {
        $this->compiler = new RequestCompiler($evaluationEngine);
    }

    /**
     * @return array{payload: OpenApiPayload, resolvedInputs: array<string, mixed>}
     */
    public function compile(Step $step, ArazzoDocument $document, WorkflowContext $context): array
    {
        return $this->compiler->compile($step, $document, $context);
    }

    /**
     * @return array{statusCode: int, headers: array<string, string>, body: array<string, mixed>, rawBody: string, contentType: string}
     */
    public function decodeResponse(ResponseInterface $response): array
    {
        return $this->compiler->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function requestRecord(?RequestInterface $captured, OpenApiPayload $payload): array
    {
        return $this->compiler->requestRecord($captured, $payload);
    }

    public function shouldValidateSchema(
        Step $step,
        string $sourceName,
        ?bool $sourceStrict,
        bool $fallbackStrict,
    ): bool {
        $policyStrict = $this->policy?->forSource($sourceName)->strictValidation;

        return $step->flow->strictValidation
            ?? $sourceStrict
            ?? $policyStrict
            ?? $fallbackStrict;
    }

    public function resolveIdempotencyInjector(
        string $sourceName,
        ?IdempotencyKeyInjector $explicitInjector,
    ): ?IdempotencyKeyInjector {
        if ($explicitInjector !== null) {
            return $explicitInjector;
        }

        $effective = $this->policy?->forSource($sourceName);
        if ($effective !== null && $effective->idempotency->enabled) {
            return new IdempotencyKeyInjector(true, $effective->idempotency->headerName);
        }

        return null;
    }
}
