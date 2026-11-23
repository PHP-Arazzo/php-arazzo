<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Condition\ConditionEvaluator;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;

/**
 * Evaluates the built-in criterion types against a root the caller has already
 * resolved.
 *
 * Selector, regex, and condition failures propagate so
 * {@see CriteriaEvaluator} can classify them into a
 * {@see CriterionResult}.
 *
 * Selector evaluation (JSONPath, XPath, JSON Pointer) is delegated through
 * {@see ExpressionEngineInterface}. The engine is type-agnostic; new expression
 * types can be added via `#[AsSelectorPlugin]` implementations.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class BuiltInCriterionEvaluator
{
    public function __construct(
        private ExpressionEngineInterface $expression,
        private ConditionEvaluator $conditionEvaluator,
    ) {}

    public function evaluate(
        SuccessCriterion $criterion,
        CriterionType $type,
        mixed $root,
        EvaluationContext $context,
    ): bool {
        return match ($type) {
            CriterionType::Simple => $this->conditionEvaluator->evaluate(
                $criterion->condition,
                $context->getWorkflowContext(),
                $context->getCurrentStepId() ?? '',
                $context->getDocument(),
            ),
            CriterionType::Regex => $this->evaluateRegex($criterion, $root),
            CriterionType::JsonPath => $this->evaluateJsonPath($criterion, $root),
            CriterionType::XPath => $this->evaluateXPath($criterion, $root),
        };
    }

    private function evaluateRegex(SuccessCriterion $criterion, mixed $root): bool
    {
        $matched = preg_match('/'.str_replace('/', '\/', $criterion->condition).'/', self::stringify($root));

        if ($matched === false) {
            throw new \RuntimeException("Invalid regular expression: {$criterion->condition}");
        }

        return $matched === 1;
    }

    private function evaluateJsonPath(SuccessCriterion $criterion, mixed $root): bool
    {
        if (!is_array($root) && !is_object($root)) {
            return false;
        }

        $result = $this->expression->jsonPath($criterion->condition, $root);

        return !empty($result);
    }

    private function evaluateXPath(SuccessCriterion $criterion, mixed $root): bool
    {
        // The Arazzo default is xpath-31, which this engine implements via
        // Xpath31Evaluator (backed by DOMXPath for the XPath 1.0 subset).
        // A versionless criterion therefore runs as xpath-10; explicit
        // xpath-31 declarations are accepted; xpath-20/xpath-30 are refused
        // at validation time rather than failing here.
        $version = $criterion->version ?? 'xpath-10';

        return ConditionEvaluator::truthy($this->expression->xpath($criterion->condition, $root, $version));
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value) && !array_is_list($value)) {
            // Non-list arrays stringify as JSON for regex matching convenience.
            $encoded = json_encode($value);

            return $encoded === false ? '' : $encoded;
        }

        if (is_array($value)) {
            return implode(',', array_map(self::stringify(...), $value));
        }

        return '';
    }
}
