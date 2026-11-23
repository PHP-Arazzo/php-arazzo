<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\JsonPath;

use RuntimeException;

/**
 * Recursive-descent parser for RFC 9535 JSONPath filter predicates (`[?...]`).
 *
 * @internal stays out of the advertised contract; consumed by JsonPath\Parser.
 */
final readonly class FilterParser
{
    private const array COMPARATORS = ['==', '!=', '<', '>', '<=', '>=', '=~'];

    private const array FUNCTIONS = ['length', 'count', 'match', 'search', 'value'];

    private const array BOOLEAN_NULL = ['null', 'true', 'false'];

    public function __construct(
        private TokenCursor $cursor,
    ) {}

    /**
     * @return array{kind: string}
     */
    public function parseExpression(): array
    {
        return $this->parseOr();
    }

    /**
     * @return array{kind: string}
     */
    private function parseOr(): array
    {
        $left = $this->parseAnd();

        while ($this->cursor->isValue('||')) {
            $this->cursor->advance();
            $right = $this->parseAnd();
            $left = ['kind' => 'logical', 'operator' => '||', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * @return array{kind: string}
     */
    private function parseAnd(): array
    {
        $left = $this->parseNot();

        while ($this->cursor->isValue('&&')) {
            $this->cursor->advance();
            $right = $this->parseAnd();
            $left = ['kind' => 'logical', 'operator' => '&&', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * @return array{kind: string}
     */
    private function parseNot(): array
    {
        if ($this->cursor->isValue('!')) {
            $this->cursor->advance();
            $operand = $this->parseNot();

            return ['kind' => 'logical', 'operator' => '!', 'left' => $operand, 'right' => null];
        }

        return $this->parseComparison();
    }

    /**
     * @return array{kind: string}
     */
    private function parseComparison(): array
    {
        $left = $this->parsePrimary();
        $value = $this->cursor->current()[1];

        if (in_array($value, self::COMPARATORS, true)) {
            $this->cursor->advance();
            $right = $this->parsePrimary();

            return ['kind' => 'comparison', 'operator' => $value, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    /**
     * @return array{kind: string}
     */
    private function parsePrimary(): array
    {
        [$type, $value] = $this->cursor->current();

        if ($type === 'WORD') {
            return $this->parseWordPrimary(is_string($value) ? $value : '');
        }

        if ($type === 'CURRENT' && $value === '@') {
            return $this->parseCurrentReference();
        }

        return $this->parseNonWordPrimary($type, $value);
    }

    /**
     * @return array{kind: string}
     */
    private function parseWordPrimary(string $value): array
    {
        if ($value === 'exists') {
            return $this->parseExists();
        }

        if (in_array($value, self::FUNCTIONS, true)) {
            return $this->parseFunction($value);
        }

        if ($value === '@') {
            return $this->parseCurrentReference();
        }

        if (in_array($value, self::BOOLEAN_NULL, true)) {
            $this->cursor->advance();

            return ['kind' => 'literal', 'value' => $value === 'null' ? null : ($value === 'true')];
        }

        throw new RuntimeException('Unexpected token in filter expression: '.var_export($value, true));
    }

    /**
     * @return array{kind: string}
     */
    private function parseNonWordPrimary(string $type, mixed $value): array
    {
        if ($type === 'STRING') {
            $this->cursor->advance();

            return ['kind' => 'literal', 'value' => $value];
        }

        if (is_numeric($value)) {
            $this->cursor->advance();

            return ['kind' => 'literal', 'value' => (float) $value];
        }

        if ($type === 'DOT') {
            return $this->parsePathExpression();
        }

        if ($type === 'LPAREN') {
            $this->cursor->advance();
            $expr = $this->parseExpression();
            $this->cursor->expect('RPAREN', ')');

            return $expr;
        }

        throw new RuntimeException('Unexpected token in filter expression: '.var_export($value, true));
    }

    /**
     * @return array{kind: string, expression: array{kind: string, name: string}}
     */
    private function parseExists(): array
    {
        $this->cursor->advance();
        $this->cursor->expect('LPAREN', '(');
        $expr = $this->parsePathExpression();
        $this->cursor->expect('RPAREN', ')');

        return ['kind' => 'exists', 'expression' => $expr];
    }

    /**
     * @return array{kind: string, name?: string|null, base?: string}
     */
    private function parseCurrentReference(): array
    {
        $this->cursor->advance();
        if ($this->cursor->current()[0] === 'DOT') {
            $this->cursor->advance();
            $nameToken = $this->cursor->current();
            if ($nameToken[0] !== 'WORD') {
                throw new RuntimeException('Expected field name after @.');
            }
            $this->cursor->advance();

            return ['kind' => 'field', 'name' => is_scalar($nameToken[1]) ? (string) $nameToken[1] : null, 'base' => '@'];
        }

        return ['kind' => 'current'];
    }

    /**
     * @return array{kind: string, name: string}
     */
    private function parsePathExpression(): array
    {
        [$type, $value] = $this->cursor->current();

        if (($type === 'WORD' || $type === 'CURRENT') && ($value === '@' || $value === '$')) {
            $this->cursor->advance();
            $this->cursor->expect('DOT', '.');
        } elseif ($type === 'DOT') {
            $this->cursor->advance();
        }

        $token = $this->cursor->current();
        if ($token[0] !== 'WORD') {
            throw new RuntimeException('Expected field name in path expression');
        }

        $this->cursor->advance();

        return ['kind' => 'field', 'name' => is_scalar($token[1]) ? (string) $token[1] : ''];
    }

    /**
     * @return array{kind: string, name: string, args: list<array<array-key, mixed>>}
     */
    private function parseFunction(string $name): array
    {
        $this->cursor->advance();
        $this->cursor->expect('LPAREN', '(');

        $args = [];
        if (!$this->cursor->isType('RPAREN')) {
            $args[] = $this->parseExpression();

            while ($this->cursor->isType('COMMA')) {
                $this->cursor->advance();
                $args[] = $this->parseExpression();
            }
        }

        $this->cursor->expect('RPAREN', ')');

        return ['kind' => 'function', 'name' => $name, 'args' => $args];
    }
}
