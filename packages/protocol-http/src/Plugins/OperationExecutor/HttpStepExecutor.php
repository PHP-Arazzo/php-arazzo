<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\RequestValidatorInterface;
use Alama\Arazzo\Contracts\Interfaces\ResponseValidatorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Engine\Execution\RequestCompiler;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Protocol\Http\Execution\Interfaces\OpenApiExecutorInterface;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Psr\Http\Message\RequestInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsOperationExecutorPlugin(OperationProtocol::Http)]
final class HttpStepExecutor implements OperationExecutorPluginInterface
{
    /**
     * Registry ordering (ascending, first supports() wins). HTTP steps and
     * AsyncAPI actions are disjoint, so the value only stabilises iteration.
     */
    public const PLUGIN_PRIORITY = 100;

    public function __construct(
        private OpenApiExecutorInterface $openApiExecutor,
        private OpenApiOperationResolver $operationResolver,
        private EvaluationEngineInterface $evaluationEngine,
        private OutputExtractorInterface $outputExtractor,
        private ResponseValidatorInterface $schemaValidator,
        private bool $strictDefault = true,
        private ?IdempotencyKeyInjector $injector = null,
        private ?RequestValidatorInterface $requestValidator = null,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function priority(): int
    {
        return self::PLUGIN_PRIORITY;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return !$step->hasAction();
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        $compiler = new RequestCompiler($this->evaluationEngine);
        ['payload' => $payload, 'resolvedInputs' => $resolvedInputs] = $compiler->compile($step, $document, $context);

        $resolved = $this->operationResolver->resolve($step, $document);
        $sourcePolicy = $context->getActiveSourcePolicy();
        $timeoutSeconds = $step->flow->timeoutSeconds() ?? $sourcePolicy?->timeoutSeconds();

        if ($this->requestValidator !== null && $this->shouldValidateSchema($step, $sourcePolicy?->strictValidation())) {
            $this->requestValidator->validateRequest($step, $resolved, $payload, $document);
        }

        /** @var RequestInterface|null $capturedRequest */
        $capturedRequest = null;
        try {
            $response = $this->openApiExecutor->execute(
                $resolved,
                $payload,
                function ($request) use ($context, $step, &$capturedRequest) {
                    /** @var RequestInterface $request */
                    $capturedRequest = $request;

                    if ($this->injector !== null) {
                        return $this->injector->inject($request, $step, $context)->request;
                    }

                    return $request;
                },
                $timeoutSeconds,
                $sourcePolicy,
            );
        } catch (\Throwable $e) {
            if (method_exists($e, 'getErrorCode') && $e->getErrorCode() === 'transport.rate_limited') {
                return StepExecutionOutcome::resolved(
                    429,
                    [],
                    ['error' => 'transport.rate_limited', 'message' => $e->getMessage()],
                    failureCategory: 'transport',
                );
            }

            // Transport-level failures become a synthetic 500 response so
            // failureActions (e.g. retry) apply identically to the sync
            // adapter's StepExecutor policy.
            return StepExecutionOutcome::resolved(500, [], ['error' => $e->getMessage()], failureCategory: 'transport');
        }

        $decoded = $compiler->decodeResponse($response);

        if ($this->shouldValidateSchema($step, $sourcePolicy?->strictValidation())) {
            $this->schemaValidator->validateResponseSchema(
                $step,
                $decoded['statusCode'],
                $decoded['contentType'],
                $decoded['body'],
                $document,
            );
        }

        $requestRecord = $compiler->requestRecord($capturedRequest, $payload);

        $contextWithResponse = $context
            ->withStepRequest($step->stepId, $requestRecord)
            ->withStepResponse($step->stepId, [
                'statusCode' => $decoded['statusCode'],
                'headers' => $decoded['headers'],
                'body' => $decoded['body'],
            ]);

        $outputs = $this->outputExtractor->extractOutputs($step, $contextWithResponse, $document);

        return StepExecutionOutcome::resolved(
            $decoded['statusCode'],
            $outputs,
            $decoded['body'],
            $resolvedInputs,
            $requestRecord,
            $decoded['headers'],
            rawBody: $decoded['rawBody'],
            contentType: $decoded['contentType'],
        );
    }

    private function shouldValidateSchema(Step $step, ?bool $sourceStrict = null): bool
    {
        return $step->flow->strictValidation
            ?? $sourceStrict
            ?? $this->strictDefault;
    }
}
