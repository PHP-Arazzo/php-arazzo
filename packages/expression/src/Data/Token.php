<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Data;

use Alama\Arazzo\Expression\Enum\TokenKind;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Token
{
    public function __construct(
        public TokenKind $kind,
        public string $value,
        public int $offset,
    ) {}

    public function isKind(TokenKind $kind): bool
    {
        return $this->kind === $kind;
    }

    public function isIdentifier(): bool
    {
        return $this->kind === TokenKind::Name || $this->kind === TokenKind::Keyword;
    }

    public function isPointerSegment(): bool
    {
        return $this->kind === TokenKind::PointerSegment || $this->isIdentifier();
    }
}
