<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Condition;

use Alama\Arazzo\Evaluation\Condition\Ast\Accessor;
use Alama\Arazzo\Evaluation\Enum\TokenKind;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class Lexer
{
    private AccessorParser $accessorParser;

    public function __construct()
    {
        $this->accessorParser = new AccessorParser();
    }

    /** @return list<Token> */
    public function lex(string $condition): array
    {
        $tokens = [];
        $length = strlen($condition);
        $i = 0;

        while ($i < $length) {
            if (str_contains(" \t\n\r", $condition[$i])) {
                $i++;

                continue;
            }

            [$token, $i] = $this->scanNext($condition, $i);
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Split a captured runtime-expression operand into the longest valid
     * runtime-expression prefix and its trailing accessor chain.
     *
     * The runtime-expression grammar has no accessor syntax, while the Criterion
     * Object Operators table allows `[]` index and `.` property de-reference on
     * a runtime-expression operand. The expression parser remains the authority
     * on what a valid base is.
     *
     * @return array{string, list<Accessor>}
     */
    public function splitAccessors(string $captured): array
    {
        return $this->accessorParser->split($captured);
    }

    /**
     * @return array{Token, int}
     */
    private function scanNext(string $condition, int $i): array
    {
        $operator = $this->tryScanOperator($condition, $i);
        if ($operator !== null) {
            return $operator;
        }

        $char = $condition[$i];
        if ($char === '\'' || $char === '"') {
            return $this->scanString($condition, $i);
        }

        if ($char === '$' || $char === '{') {
            return $this->scanExpression($condition, $i);
        }

        return $this->scanNumberOrIdent($condition, $i);
    }

    /**
     * @return array{Token, int}|null
     */
    private function tryScanOperator(string $condition, int $i): ?array
    {
        $two = substr($condition, $i, 2);
        if (isset(TokenKind::OPERATORS[$two])) {
            return [new Token(TokenKind::OPERATORS[$two], $two, $i), $i + 2];
        }

        $one = $condition[$i];
        if (isset(TokenKind::OPERATORS[$one])) {
            return [new Token(TokenKind::OPERATORS[$one], $one, $i), $i + 1];
        }

        return null;
    }

    /**
     * @return array{Token, int}
     */
    private function scanExpression(string $condition, int $i): array
    {
        if ($condition[$i] === '{') {
            $closing = strpos($condition, '}', $i);
            if ($closing === false) {
                throw new ConditionSyntaxException("Unterminated '{' runtime expression in condition at offset {$i}.");
            }

            $raw = trim(substr($condition, $i + 1, $closing - $i - 1));
            $end = $closing + 1;
        } else {
            $end = $this->expressionEnd($condition, $i);
            $raw = rtrim(substr($condition, $i, $end - $i));
        }

        if ($raw === '' || $raw[0] !== '$') {
            throw new ConditionSyntaxException("Malformed runtime expression in condition at offset {$i}.");
        }

        return [new Token(TokenKind::Expr, $raw, $i), $end];
    }

    /**
     * @return array{Token, int}
     */
    private function scanNumberOrIdent(string $condition, int $i): array
    {
        $number = $this->tryScanPattern($condition, $i, '/-?\d+(?:\.\d+)?/', TokenKind::Number);
        if ($number !== null) {
            return $number;
        }

        $ident = $this->tryScanPattern($condition, $i, '/[A-Za-z_][A-Za-z0-9_.\-\/]*/', TokenKind::Ident);
        if ($ident !== null) {
            return $ident;
        }

        throw new ConditionSyntaxException("Unexpected character '{$condition[$i]}' in condition at offset {$i}.");
    }

    /**
     * @return array{Token, int}|null
     */
    private function tryScanPattern(string $condition, int $i, string $pattern, TokenKind $kind): ?array
    {
        if (preg_match($pattern, $condition, $m, PREG_OFFSET_CAPTURE, $i) === 1 && $m[0][1] === $i) {
            return [new Token($kind, $m[0][0], $i), $i + strlen($m[0][0])];
        }

        return null;
    }

    /**
     * Scan a quoted string literal starting at `$start`, returning the token and
     * the index just past its closing quote.
     *
     * @return array{Token, int}
     */
    private function scanString(string $condition, int $start): array
    {
        $length = strlen($condition);
        $quote = $condition[$start];
        $value = '';
        $i = $start + 1;

        while (true) {
            if ($i >= $length) {
                throw new ConditionSyntaxException("Unterminated string literal in condition at offset {$start}.");
            }

            if ($condition[$i] === $quote) {
                if ($quote === '\'' && ($condition[$i + 1] ?? '') === '\'') {
                    $value .= '\'';
                    $i += 2;

                    continue;
                }

                return [new Token(TokenKind::String, $value, $start), $i + 1];
            }

            $value .= $condition[$i];
            $i++;
        }
    }

    /**
     * Runtime expressions run until whitespace or a top-level delimiter.
     */
    private function expressionEnd(string $condition, int $start): int
    {
        $length = strlen($condition);
        $i = $start;

        while ($i < $length) {
            if ($this->isExpressionDelimiter($condition, $i, $start)) {
                return $i;
            }

            $i++;
        }

        return $length;
    }

    private function isExpressionDelimiter(string $condition, int $i, int $start): bool
    {
        $char = $condition[$i];
        if (str_contains(" \t)", $char)) {
            return true;
        }

        $two = substr($condition, $i, 2);
        if ($two === '&&' || $two === '||') {
            return true;
        }

        return $i > $start && str_contains('=!<>', $char);
    }
}
