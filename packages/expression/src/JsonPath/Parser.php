<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\JsonPath;

use Alama\Arazzo\Expression\Exceptions\JsonPathSyntaxException;

/**
 * Recursive-descent parser for RFC 9535 JSONPath expressions.
 *
 * @internal stays out of the advertised contract; consumed by Rfc9535Engine.
 */
final class Parser
{
    private readonly TokenCursor $cursor;

    private readonly FilterParser $filters;

    public function __construct(array $tokens)
    {
        /** @var list<array{string, mixed}> $tokens */
        $this->cursor = new TokenCursor($tokens);
        $this->filters = new FilterParser($this->cursor);
    }

    /**
     * @return array{kind: string, steps: list<array<array-key, mixed>>}
     */
    public function parse(): array
    {
        $this->cursor->expect('ROOT', '$');

        $steps = [];

        while ($this->cursor->hasMore()) {
            $token = $this->cursor->current();

            if ($token[0] === 'DOT') {
                $this->cursor->advance();
                $steps[] = $this->parseSelectorTail();
            } elseif ($token[0] === 'LBRACKET') {
                $steps[] = $this->parseBracket();
            } elseif ($token[0] === 'RECURSIVE_DESCENT') {
                $this->cursor->advance();
                $steps[] = $this->parseRecursiveDescentTail();
            } else {
                break;
            }
        }

        if ($this->cursor->hasMore()) {
            throw JsonPathSyntaxException::unexpectedTrailingTokens();
        }

        return ['kind' => 'selector', 'steps' => $steps];
    }

    /**
     * @return array{kind: string, name?: string, child?: array<array-key, mixed>|null}
     */
    private function parseSelectorTail(): array
    {
        $token = $this->cursor->current();
        $value = is_scalar($token[1]) ? (string) $token[1] : '';

        if ($token[0] === 'WORD') {
            $this->cursor->advance();

            return ['kind' => 'child', 'name' => $value];
        }

        if ($value === '*') {
            $this->cursor->advance();

            return ['kind' => 'wildcard', 'child' => null];
        }

        throw JsonPathSyntaxException::expectedMemberAfterDot();
    }

    /**
     * @return array{kind: string}
     */
    private function parseRecursiveDescentTail(): array
    {
        $token = $this->cursor->current();
        $value = $token[1];

        if ($value === '*') {
            $this->cursor->advance();

            return ['kind' => 'recursive-descent', 'child' => ['kind' => 'wildcard', 'child' => null]];
        }

        if ($token[0] === 'WORD') {
            $this->cursor->advance();

            return ['kind' => 'recursive-descent', 'child' => ['kind' => 'child', 'name' => $value]];
        }

        if ($token[0] === 'LBRACKET') {
            $this->cursor->advance();
            $inner = $this->parseBracketContent();
            $this->cursor->expect('RBRACKET', ']');

            return ['kind' => 'recursive-descent', 'child' => $inner];
        }

        throw JsonPathSyntaxException::expectedMemberAfterDescent();
    }

    /**
     * @return array{kind: string}
     */
    private function parseBracket(): array
    {
        $this->cursor->advance(); // consume '['

        if ($this->cursor->current()[1] === '?') {
            $this->cursor->advance(); // consume '?'
            $predicate = $this->filters->parseExpression();
            $this->cursor->expect('RBRACKET', ']');

            return ['kind' => 'filter', 'predicate' => $predicate];
        }

        $content = $this->parseBracketContent();
        $this->cursor->expect('RBRACKET', ']');

        return $content;
    }

    /**
     * @return array{kind: string, start?: int, end?: int, step?: int, parts?: list<array<array-key, mixed>>, name?: string, child?: array<array-key, mixed>|null}
     */
    private function parseBracketContent(): array
    {
        $first = $this->parseSelectorPart();

        if ($this->cursor->isType('COMMA')) {
            return $this->parseUnion($first);
        }

        return $first;
    }

    /**
     * @return array{kind: string, start?: int, end?: int, step?: int, name?: string, child?: array<array-key, mixed>|null}
     */
    private function parseSelectorPart(): array
    {
        [$type, $value] = $this->cursor->current();

        if ($value === '*') {
            $this->cursor->advance();

            return ['kind' => 'wildcard', 'child' => null];
        }

        if ($type === 'STRING') {
            $this->cursor->advance();

            return ['kind' => 'child', 'name' => is_scalar($value) ? (string) $value : ''];
        }

        if ($type === 'COLON') {
            return $this->parseSlice(null);
        }

        if (is_numeric($value)) {
            $this->cursor->advance();
            $start = (int) $value;

            if ($this->cursor->isType('COLON')) {
                return $this->parseSlice($start);
            }

            return ['kind' => 'slice', 'start' => $start, 'end' => $start + 1, 'step' => 1];
        }

        throw JsonPathSyntaxException::unsupportedBracketSelector();
    }

    /**
     * @return array{kind: string, start: int, end: int, step: int}
     */
    private function parseSlice(?int $start): array
    {
        $this->cursor->advance(); // consume ':'

        $end = PHP_INT_MAX;
        $step = 1;

        $token = $this->cursor->current();
        if ($token[0] !== 'COLON' && is_numeric($token[1])) {
            $this->cursor->advance();
            $end = (int) $token[1];
        }

        if ($this->cursor->isType('COLON')) {
            $this->cursor->advance(); // consume second ':'
            $stepToken = $this->cursor->current();
            if (is_numeric($stepToken[1])) {
                $this->cursor->advance();
                $step = (int) $stepToken[1];
            }
        }

        return ['kind' => 'slice', 'start' => $start ?? 0, 'end' => $end, 'step' => $step];
    }

    /**
     * @param  array{kind: string, start?: int, end?: int, step?: int, name?: string, child?: array<array-key, mixed>|null}  $first
     * @return array{kind: string, parts: list<array<array-key, mixed>>}
     */
    private function parseUnion(array $first): array
    {
        $parts = [$first];

        while ($this->cursor->isType('COMMA')) {
            $this->cursor->advance(); // consume ','
            $parts[] = $this->parseSelectorPart();
        }

        return ['kind' => 'union', 'parts' => $parts];
    }
}
