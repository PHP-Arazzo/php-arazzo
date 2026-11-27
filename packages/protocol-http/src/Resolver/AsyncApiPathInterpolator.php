<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Contracts\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Execution\ReusableParameterResolver;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Stringable;

/**
 * Interpolates {param} tokens in AsyncAPI channel paths.
 *
 * @internal
 */
final class AsyncApiPathInterpolator
{
    public function __construct(
        private readonly ?EvaluationEngineInterface $evaluationEngine = null,
    ) {}

    public function interpolate(string $path, Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
    {
        if (!str_contains($path, '{')) {
            return $path;
        }

        $parameters = new ReusableParameterResolver()->resolve($step->io->parameters, $document);
        if ($parameters === []) {
            return $path;
        }

        $evaluator = $this->evaluationEngine ?? new EvaluationEngine(expression: new ExpressionEngine());
        $evalContext = $context !== null ? new EvaluationContext($context, $step->stepId, $document) : null;

        $replacements = [];
        foreach ($parameters as $parameter) {
            $val = $parameter->value;
            if ($val instanceof Expression && $evalContext !== null) {
                $val = $evaluator->evaluate($val, $evalContext);
            }

            $stringVal = is_scalar($val) || $val instanceof Stringable ? (string) $val : '';
            $replacements['{'.$parameter->name.'}'] = $stringVal;
        }

        return strtr($path, $replacements);
    }
}
