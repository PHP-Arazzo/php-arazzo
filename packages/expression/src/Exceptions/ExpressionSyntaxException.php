<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;
use Throwable;

final class ExpressionSyntaxException extends ArazzoException
{
    public function __construct(
        string $message,
        public readonly string $expression = '',
        public readonly int $offset = -1,
        string $path = '',
        string $codeId = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $path, $codeId, $previous);
    }

    public static function atOffset(string $message, string $expression, int $offset): self
    {
        return new self($message, $expression, $offset, '', 'expr.syntax');
    }

    public static function emptyExpression(string $raw, int $offset): self
    {
        return new self("Empty expression: {$raw}", $raw, $offset, '', 'expr.syntax');
    }

    public static function illegalCharacter(string $character, int $offset, string $raw): self
    {
        return new self(
            "Illegal character '{$character}' at offset {$offset} in expression: {$raw}",
            $raw,
            $offset,
            '',
            'expr.syntax',
        );
    }

    public static function missingPrefix(string $raw): self
    {
        return new self(
            "Expression must start with $ or be wrapped in {\$...}: {$raw}",
            $raw,
            0,
            '',
            'expr.syntax',
        );
    }

    public static function unterminatedEmbedded(string $template, int $offset): self
    {
        return new self("Unterminated embedded expression in: {$template}", $template, $offset, '', 'expr.syntax');
    }

    public static function invalidEmbedded(self $cause, string $template, int $offset): self
    {
        return new self($cause->getMessage(), $template, $offset, $cause->path, $cause->codeId, $cause);
    }

    public static function malformedEmbedded(): self
    {
        return new self('Malformed embedded expression');
    }
}
