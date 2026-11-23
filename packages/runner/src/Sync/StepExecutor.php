<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Execution\Defaults\EvaluationOutputExtractor;
use Alama\Arazzo\Runner\Execution\SourcePolicyResolver;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;

/**
 * Executes a single workflow step synchronously through registered
 * {@see OperationExecutorPluginInterface} plugins.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class StepExecutor
{
    private readonly OutputExtractorInterface $outputExtractor;

    private ?ExecutionPolicy $policy = null;

    /**
     * @param  list<OperationExecutorPluginInterface>  $localExecutors
     */
    public function __construct(
        private readonly EvaluationEngineInterface $evaluationEngine,
        private readonly ?OperationExecutorRegistry $executors = null,
        private readonly array $localExecutors = [],
        ?OutputExtractorInterface $outputExtractor = null,
        ?ExecutionPolicy $policy = null,
    ) {
        $this->outputExtractor = $outputExtractor ?? new EvaluationOutputExtractor($evaluationEngine);
        $this->policy = $policy;
    }

    public function policy(): ExecutionPolicy
    {
        return $this->policy ?? ExecutionPolicy::default();
    }

    /**
     * Executes a step and returns an array with the updated WorkflowContext and a boolean success flag.
     *
     * @return array{0: WorkflowContext, 1: bool}
     */
    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document): array
    {
        $registry = $this->executors ?? OperationExecutorRegistry::create();
        $plugin = $registry->resolve($step, $document, $this->localExecutors);

        if ($plugin === null) {
            throw new ExecutionException("No OperationExecutorPluginInterface supports step '{$step->stepId}'. Register a protocol executor plugin (e.g. HttpProtocol::create()->executor()).");
        }

        $sourcePolicy = (new SourcePolicyResolver())->resolveForStep($step, $document, $this->policy());
        $scopedContext = $context->withActiveSourcePolicy($sourcePolicy);

        $outcome = $plugin->execute($step, $scopedContext, $document, $context->getExecutionId());

        if ($outcome->suspended) {
            throw new ExecutionException("Step '{$step->stepId}' suspended, which the synchronous runner cannot resume.");
        }

        // Keep source credentials strictly scoped to the single plugin call.
        $context = $context->withActiveSourcePolicy(null);
        $context = $context->withStepRequest($step->stepId, $outcome->request ?? []);
        $context = $context->withStepResponse($step->stepId, array_filter([
            'statusCode' => $outcome->statusCode,
            'headers' => $outcome->responseHeaders,
            'body' => $outcome->responseBody,
            'rawBody' => $outcome->rawBody,
            'contentType' => $outcome->contentType,
            'failureCategory' => $outcome->failureCategory,
        ], static fn (mixed $value): bool => $value !== null));

        foreach ($outcome->outputs as $name => $value) {
            $context = $context->withStepOutput($step->stepId, $name, $value);
        }

        if ($step->io->outputs !== []) {
            foreach ($this->outputExtractor->extractOutputs($step, $context, $document) as $name => $value) {
                if (!array_key_exists($name, $outcome->outputs)) {
                    $context = $context->withStepOutput($step->stepId, $name, $value);
                }
            }
        }

        return [$context, $this->evaluationEngine->evaluateSuccessCriteria($step, $context, $document)];
    }
}
