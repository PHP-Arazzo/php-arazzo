<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Condition;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class ConditionSyntaxException extends ArazzoException
{
    public static function emptyCondition(): self
    {
        return new self('Condition is empty.');
    }

    public static function unexpectedEnd(): self
    {
        return new self('Unexpected end of condition.');
    }

    public static function unexpectedToken(string $value): self
    {
        return new self("Unexpected '{$value}' in condition.");
    }

    public static function unexpectedTrailing(string $value): self
    {
        return new self("Unexpected trailing input in condition near '{$value}'.");
    }

    public static function unexpectedOperand(string $value): self
    {
        return new self("Unexpected operand '{$value}' in condition.");
    }

    public static function unknownIdentifier(string $value): self
    {
        return new self("Unknown identifier '{$value}' in condition; expected a runtime expression or literal.");
    }

    public static function expectedClosingParen(?string $near): self
    {
        return new self("Expected closing parenthesis in condition near '{$near}'.");
    }

    public static function malformedRuntimeExpr(string $captured): self
    {
        return new self("Malformed runtime expression in condition: {$captured}.");
    }

    public static function malformedAccessor(string $rest): self
    {
        return new self("Malformed accessor in condition runtime expression: {$rest}.");
    }

    public static function unsupportedNode(): self
    {
        return new self('Unsupported condition node.');
    }
}
