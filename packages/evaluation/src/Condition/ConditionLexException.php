<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Condition;

/**
 * Lexical syntax failure inside a simple condition expression.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ConditionLexException extends ConditionSyntaxException
{
    public static function unterminatedExpression(int $offset): self
    {
        return new self("Unterminated '{' runtime expression in condition at offset {$offset}.");
    }

    public static function malformedExpression(int $offset): self
    {
        return new self("Malformed runtime expression in condition at offset {$offset}.");
    }

    public static function unexpectedCharacter(string $character, int $offset): self
    {
        return new self("Unexpected character '{$character}' in condition at offset {$offset}.");
    }

    public static function unterminatedString(int $offset): self
    {
        return new self("Unterminated string literal in condition at offset {$offset}.");
    }
}
