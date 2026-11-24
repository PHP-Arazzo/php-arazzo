<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Condition;

use Alama\Arazzo\Evaluation\Enum\TokenKind;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Token
{
    private const array TERMINAL_KINDS = [
        TokenKind::Number,
        TokenKind::String,
        TokenKind::Ident,
        TokenKind::Expr,
    ];

    public function __construct(
        public TokenKind $kind,
        public string $value,
        public int $offset,
    ) {}

    public function isKind(TokenKind $kind): bool
    {
        return $this->kind === $kind;
    }

    public function isTerminal(): bool
    {
        return in_array($this->kind, self::TERMINAL_KINDS, true);
    }
}
