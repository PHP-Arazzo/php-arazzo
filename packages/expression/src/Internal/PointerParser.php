<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Internal;

use Alama\Arazzo\Expression\Data\Token;
use Alama\Arazzo\Expression\Enum\TokenKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;

/**
 * Parses JSON pointer suffixes, bracket array-index chains, and dotted header/parameter names.
 *
 * @internal stays out of the advertised contract; consumed by Parser.
 */
final class PointerParser
{
    /** @param list<Token> $tail */
    public function parseJsonPointer(array $tail, string $raw): ?string
    {
        if ($tail === []) {
            return null;
        }
        if ($tail[0]->kind !== TokenKind::Hash) {
            $this->fail("Expected '#' before JSON pointer in: {$raw}", $raw, $tail[0]);
        }

        $out = '';
        $i = 1;
        $tokenCount = count($tail);
        while ($i < $tokenCount) {
            if ($tail[$i]->kind !== TokenKind::Slash) {
                $this->fail("Expected '/' in JSON pointer at token {$i} in: {$raw}", $raw, $tail[$i]);
            }
            $out .= '/';
            $i++;
            if ($i < $tokenCount && $this->isPointerSegmentToken($tail[$i])) {
                $out .= $tail[$i]->value;
                $i++;
            }
        }

        return $out;
    }

    /**
     * Parse a run of one or more `[index]` segments, each optionally followed
     * by a `.name` segment, into a JSON pointer string, e.g. `[0].id` -> `/0/id`.
     *
     * @param  list<Token>  $rest  starting at the '[' token
     */
    public function parseArrayIndexPointer(array $rest, string $raw): string
    {
        $out = '';
        $i = 0;
        $count = count($rest);

        while ($i < $count) {
            $out .= $this->parseIndexSegment($rest, $i, $count, $raw);
        }

        return $out;
    }

    /** @param list<Token> $tail */
    public function parseHeaderName(array $tail, string $raw): string
    {
        if (count($tail) !== 2 || $tail[0]->kind !== TokenKind::Dot || !$this->isIdentifierToken($tail[1])) {
            $at = $this->firstUnexpected($tail, [
                0 => [TokenKind::Dot],
                1 => [TokenKind::Name, TokenKind::Keyword],
            ]);
            if ($at === null && isset($tail[2])) {
                $at = $tail[2];
            }
            $this->fail("Expected '.name' after header in: {$raw}", $raw, $at);
        }

        return $tail[1]->value;
    }

    public function isIdentifierToken(Token $token): bool
    {
        return $token->kind === TokenKind::Name || $token->kind === TokenKind::Keyword;
    }

    /**
     * Find the first token that violates the expected kinds at its index.
     *
     * @param  list<Token>  $tokens
     * @param  array<int, list<TokenKind>>  $expected
     */
    public function firstUnexpected(array $tokens, array $expected): ?Token
    {
        foreach ($expected as $index => $kinds) {
            if (!isset($tokens[$index])) {
                return null;
            }
            if (!in_array($tokens[$index]->kind, $kinds, true)) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /**
     * Throw a syntax error carrying the offending token's byte offset, or the
     * end of the input when the failure is an absent token.
     */
    public function fail(string $message, string $raw, ?Token $at = null): never
    {
        throw new ExpressionSyntaxException($message, $raw, $at->offset ?? strlen($raw), '', 'expr.syntax');
    }

    private function isPointerSegmentToken(Token $token): bool
    {
        return $token->kind === TokenKind::PointerSegment
            || $token->kind === TokenKind::Name
            || $token->kind === TokenKind::Keyword;
    }

    /**
     * @param  list<Token>  $rest
     */
    private function parseIndexSegment(array $rest, int &$i, int $count, string $raw): string
    {
        if ($rest[$i]->kind !== TokenKind::LeftBracket) {
            $this->fail("Expected '[' in array index in: {$raw}", $raw, $rest[$i]);
        }
        $i++;
        if ($i >= $count || $rest[$i]->kind !== TokenKind::Name) {
            $this->fail("Expected array index in: {$raw}", $raw, $rest[$i] ?? null);
        }
        $out = '/'.$rest[$i]->value;
        $i++;
        if ($i >= $count || $rest[$i]->kind !== TokenKind::RightBracket) {
            $this->fail("Expected ']' after array index in: {$raw}", $raw, $rest[$i] ?? null);
        }
        $i++;

        if ($i < $count && $rest[$i]->kind === TokenKind::Dot) {
            $i++;
            if ($i >= $count || !$this->isIdentifierToken($rest[$i])) {
                $this->fail("Expected name after '.' in: {$raw}", $raw, $rest[$i] ?? null);
            }
            $out .= '/'.$rest[$i]->value;
            $i++;
        }

        return $out;
    }
}
