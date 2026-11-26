<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Support;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;
use Alama\Arazzo\Expression\Data\ExpressionSite;
use Alama\Arazzo\Expression\Enum\ExpressionContext;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ActionWalker
{
    private const string RUNTIME_EXPR_PATTERN = '/\{\$([^}]+)\}|\$\{([^}]+)\}|\$(?:inputs|outputs|steps|workflows|sourceDescriptions|components|response|request|url|method|statusCode|message|self)(?:[A-Za-z0-9_\-\.#~%@+:\/]+|\b)/';

    /**
     * @param  array<mixed>  $actions
     * @return iterable<ExpressionSite>
     */
    public function walk(array $actions, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        foreach ($actions as $ai => $action) {
            if ($action instanceof Reusable || !is_object($action)) {
                continue;
            }

            $actionPointer = "{$basePointer}/{$ai}";

            if (isset($action->criteria) && is_array($action->criteria)) {
                yield from $this->walkCriteria($action->criteria, "{$actionPointer}/criteria", $syms, $stepId, $context);
            }

            if (isset($action->parameters) && is_array($action->parameters)) {
                yield from $this->walkActionParameters($action->parameters, "{$actionPointer}/parameters", $syms, $stepId, $context);
            }
        }
    }

    /**
     * @param  array<mixed>  $parameters
     * @return iterable<ExpressionSite>
     */
    public function walkActionParameters(array $parameters, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        if (array_is_list($parameters)) {
            foreach ($parameters as $pi => $p) {
                if ($p instanceof Reusable) {
                    continue;
                }
                $val = is_object($p) && property_exists($p, 'value') ? $p->value : $p;
                if ($val instanceof Expression) {
                    yield new ExpressionSite("{$basePointer}/{$pi}/value", $val, $syms, $stepId, $context);
                }
            }
        } else {
            foreach ($parameters as $name => $value) {
                if ($value instanceof Expression) {
                    yield new ExpressionSite("{$basePointer}/{$name}", $value, $syms, $stepId, $context);
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $criteria
     * @return iterable<ExpressionSite>
     */
    public function walkCriteria(array $criteria, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        foreach ($criteria as $ci => $c) {
            if (!is_object($c)) {
                continue;
            }
            if (!empty($c->context) && is_string($c->context)) {
                foreach ($this->extractExpressionTokens($c->context) as $token) {
                    yield new ExpressionSite("{$basePointer}/{$ci}/context", new Expression($token), $syms, $stepId, $context);
                }
            }
            if (!empty($c->condition) && is_string($c->condition)) {
                foreach ($this->extractExpressionTokens($c->condition) as $token) {
                    yield new ExpressionSite("{$basePointer}/{$ci}/condition", new Expression($token), $syms, $stepId, $context);
                }
            }
        }
    }

    /** @return list<string> */
    public function extractExpressionTokens(string $text): array
    {
        $tokens = [];
        if (preg_match_all(self::RUNTIME_EXPR_PATTERN, $text, $matches) !== false) {
            foreach ($matches[0] as $raw) {
                if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
                    $inner = trim(substr($raw, 1, -1));
                    if ($inner !== '' && $inner !== '$') {
                        $tokens[] = str_starts_with($inner, '$') ? $inner : '$'.$inner;
                    }
                } else {
                    $tokens[] = rtrim($raw, " \t\n\r,;)");
                }
            }
        }

        return $tokens;
    }
}
