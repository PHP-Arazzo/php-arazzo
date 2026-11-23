<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\JsonPathEvaluator;

/**
 * Validates that every `jsonpath` Criterion Object condition parses as a valid
 * JSONPath expression (Arazzo 1.1 §5.8.11.4.3).
 *
 * Extracted from OfficialSchemaRule into `arazzo-expression` so `arazzo-document`
 * remains completely free of expression-engine dependencies.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class JsonPathCriterionConditionRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly JsonPathEvaluator $jsonPathEvaluator = new JsonPathEvaluator(),
    ) {}

    public function code(): string
    {
        return 'expr.jsonpath_condition';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $raw = $doc->rawRoot;
        if (!is_array($raw)) {
            return;
        }

        self::walkRaw($raw, '', function (array $node, string $path) use ($errors): void {
            $this->checkJsonPathCondition($node, $path, $errors);
        });
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function checkJsonPathCondition(array $node, string $path, ErrorCollector $errors): void
    {
        $condition = $node['condition'] ?? null;
        if (!is_string($condition)) {
            return;
        }

        $type = $node['type'] ?? null;
        $isJsonPath = $type === 'jsonpath'
            || (is_array($type) && ($type['type'] ?? null) === 'jsonpath');

        if (!$isJsonPath || $this->isJsonPathQuery($condition)) {
            return;
        }

        $errors->error(
            $this->code(),
            "Criterion condition '{$condition}' is not a valid JSONPath query; a jsonpath condition MUST be a JSONPath expression (Arazzo 1.1 §5.8.11.4.3).",
            $path.'/condition',
        );
    }

    /**
     * Reports whether the condition parses as a JSONPath query.
     */
    private function isJsonPathQuery(string $condition): bool
    {
        $condition = trim($condition);

        if ($condition === '' || preg_match('/^\$(?=$|[.\[])/', $condition) !== 1) {
            return false;
        }

        try {
            $this->jsonPathEvaluator->evaluate($condition, []);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  callable(array<array-key, mixed>, string): void  $visit
     */
    private static function walkRaw(array $node, string $path, callable $visit): void
    {
        $visit($node, $path);

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $escaped = str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            self::walkRaw($value, $path.'/'.$escaped, $visit);
        }
    }
}
