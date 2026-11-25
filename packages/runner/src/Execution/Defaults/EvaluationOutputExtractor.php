<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Defaults;

use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;

/**
 * Protocol-agnostic output extractor backed solely by {@see EvaluationEngineInterface}.
 *
 * Evaluates step `outputs` declarations (selectors, runtime expressions,
 * JSONPath against response body, and literals) without protocol/OpenAPI
 * schema coupling.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class EvaluationOutputExtractor implements OutputExtractorInterface
{
    public function __construct(
        private EvaluationEngineInterface $evaluationEngine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extractOutputs(Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): array
    {
        $responseBody = self::responseBody($context, $step);

        $outputs = [];
        foreach ($step->io->outputs as $outputName => $expression) {
            if ($expression instanceof Selector) {
                $outputs[$outputName] = $this->evaluationEngine->evaluateSelector($expression, $context, $step->stepId);

                continue;
            }

            if ($expression instanceof Expression) {
                $raw = trim($expression->raw);
                if (str_starts_with($raw, '$.')) {
                    $outputs[$outputName] = $this->evaluationEngine->jsonPath($raw, is_array($responseBody) ? $responseBody : []);

                    continue;
                }

                $outputs[$outputName] = $this->evaluationEngine->evaluate(
                    $expression,
                    new EvaluationContext($context, $step->stepId, $document),
                );

                continue;
            }

            $outputs[$outputName] = $expression;
        }

        return $outputs;
    }

    private static function responseBody(WorkflowContextInterface $context, Step $step): mixed
    {
        $record = $context->getSteps()[$step->stepId] ?? null;
        if (!is_array($record)) {
            return [];
        }

        $response = $record['response'] ?? null;
        if (!is_array($response)) {
            return [];
        }

        return $response['body'] ?? [];
    }
}
