<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses value/selector expressions, parameters, criteria, reusables, and output maps.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ValueParser extends FieldParser
{
    public function parseParameter(mixed $node, ParseContext $ctx): Parameter|Reusable
    {
        $obj = $this->requireObjectMap($node, $ctx);

        if (array_key_exists('reference', $obj)) {
            return new Reusable(
                reference: $this->requireString($obj, 'reference', $ctx),
                value: $this->normalizeReusableValue($obj['value'] ?? null, $ctx->push('value')),
            );
        }

        $in = null;
        if (($rawIn = $this->optionalString($obj, 'in', $ctx)) !== null) {
            $in = ParameterIn::tryFrom($rawIn)
                ?? throw ParserException::invalidEnum(
                    $ctx->push('in'), 'path|query|header|cookie|body|querystring', $rawIn,
                );
        }
        if (!array_key_exists('value', $obj)) {
            throw ParserException::missingField($ctx, 'value');
        }

        return new Parameter(
            name: $this->requireString($obj, 'name', $ctx),
            in: $in,
            value: $this->parseValueOrSelector($obj['value'], $ctx->push('value'), false),
        );
    }

    /**
     * @return Expression|Selector|scalar|array<mixed>|null
     */
    public function parseValueOrSelector(mixed $value, ParseContext $ctx, bool $forceExpression = false): mixed
    {
        if (is_string($value)) {
            return $forceExpression || preg_match('/^\{\$.+\}$/', $value) === 1
                ? new Expression($value)
                : $value;
        }

        if (is_array($value)) {
            if (array_key_exists('selector', $value) && array_key_exists('type', $value)) {
                return $this->parseSelector($value, $ctx);
            }

            return $this->parseValueMapping($value, $ctx, $forceExpression);
        }

        if ($this->isScalarValue($value)) {
            return $value;
        }

        throw ParserException::wrongType($ctx, 'scalar, map, selector or expression', $value);
    }

    public function parseSelector(mixed $node, ParseContext $ctx): Selector
    {
        $value = $this->requireObjectMap($node, $ctx);

        $rawType = $value['type'] ?? null;
        $version = null;

        if (is_array($rawType)) {
            $typeCtx = $ctx->push('type');
            $typeNode = $this->requireObjectMap($rawType, $typeCtx);
            $typeStr = $this->requireString($typeNode, 'type', $typeCtx);
            $version = $this->optionalString($typeNode, 'version', $typeCtx);
        } else {
            $typeStr = $this->requireString($value, 'type', $ctx);
        }

        $type = ExpressionType::tryFrom($typeStr);
        if ($type === null) {
            throw ParserException::invalidEnum($ctx->push('type'), 'simple|regex|jsonpath|xpath', $typeStr);
        }

        /** @var array<string, string>|null $namespaces */
        $namespaces = $this->optionalArray($value, 'namespaces', $ctx);

        return new Selector(
            context: $this->optionalString($value, 'context', $ctx),
            selector: $this->requireString($value, 'selector', $ctx),
            type: $type,
            version: $version ?? $this->optionalString($value, 'version', $ctx),
            namespaces: $namespaces,
        );
    }

    public function parseExpressionOrValue(mixed $node): mixed
    {
        if (is_string($node) && preg_match('/^\{\$.+\}$/', $node) === 1) {
            return new Expression($node);
        }

        return $node;
    }

    public function parseSuccessCriterion(mixed $node, ParseContext $ctx): SuccessCriterion
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $type = null;
        $version = null;

        if (array_key_exists('type', $obj) && $obj['type'] !== null) {
            $rawType = $obj['type'];

            if (is_array($rawType)) {
                $typeCtx = $ctx->push('type');
                $typeNode = $this->requireObjectMap($rawType, $typeCtx);
                $t = $this->requireString($typeNode, 'type', $typeCtx);
                $version = $this->optionalString($typeNode, 'version', $typeCtx);
            } else {
                $t = $this->requireString($obj, 'type', $ctx);
            }

            $type = CriterionType::tryFrom($t)
                ?? throw ParserException::invalidEnum(
                    $ctx->push('type'), 'simple|regex|jsonpath|xpath', $t,
                );
        }

        return new SuccessCriterion(
            context: $this->optionalString($obj, 'context', $ctx),
            condition: $this->requireString($obj, 'condition', $ctx),
            type: $type,
            version: $version,
        );
    }

    public function parseReusable(mixed $node, ParseContext $ctx): Reusable
    {
        $obj = $this->requireObjectMap($node, $ctx);

        return new Reusable(
            reference: $this->requireString($obj, 'reference', $ctx),
            value: $this->normalizeReusableValue($obj['value'] ?? null, $ctx->push('value')),
        );
    }

    /** @return array<string,Expression|Selector|scalar|array<mixed>|null> */
    public function parseOutputsMap(mixed $node, ParseContext $ctx): array
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $out = [];
        foreach ($obj as $k => $v) {
            $out[$k] = $this->parseValueOrSelector($v, $ctx->push((string) $k), true);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array<string, Expression|Selector|array<mixed>|scalar|null>
     */
    public function parseInvokeParameters(array $d, ParseContext $ctx): array
    {
        $parameters = [];
        if (array_key_exists('parameters', $d) && $d['parameters'] !== null) {
            $paramsMap = $this->requireObjectMap($d['parameters'], $ctx->push('parameters'));
            foreach ($paramsMap as $k => $v) {
                $parameters[(string) $k] = $this->parseValueOrSelector($v, $ctx->push('parameters')->push((string) $k), true);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string,mixed>  $obj
     * @return list<Parameter|Reusable>
     */
    public function parseActionParameters(array $obj, ParseContext $ctx): array
    {
        if (!isset($obj['parameters']) || !is_array($obj['parameters'])) {
            return [];
        }

        if (!isset($obj['workflowId'])) {
            throw ParserException::missingField($ctx->push('parameters')->push('workflowId'), 'workflowId');
        }

        $params = [];
        foreach (array_values($obj['parameters']) as $i => $item) {
            $params[] = $this->parseParameter($item, $ctx->push('parameters')->push((string) $i));
        }

        return $params;
    }

    /**
     * @param  array<string,mixed>  $obj
     * @return list<SuccessCriterion>
     */
    public function parseCriteriaList(array $obj, ParseContext $ctx): array
    {
        $list = $this->optionalList($obj, 'criteria', $ctx);
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach ($list as $i => $item) {
            $out[] = $this->parseSuccessCriterion($item, $ctx->push('criteria')->push($i));
        }

        return $out;
    }

    /**
     * @return scalar|array<mixed>|null
     */
    private function normalizeReusableValue(mixed $value, ParseContext $ctx): mixed
    {
        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        throw ParserException::wrongType($ctx, 'scalar, array, expression or selector', $value);
    }

    /**
     * @param  array<array-key,mixed>  $value
     * @return array<array-key,mixed>
     */
    private function parseValueMapping(array $value, ParseContext $ctx, bool $forceExpression): array
    {
        $parsed = [];
        foreach ($value as $k => $v) {
            $parsed[$k] = $this->parseValueOrSelector($v, $ctx->push((string) $k), $forceExpression);
        }

        return $parsed;
    }

    /**
     * @phpstan-assert-if-true scalar|null $value
     */
    private function isScalarValue(mixed $value): bool
    {
        return is_bool($value) || is_int($value) || is_float($value) || $value === null;
    }
}
