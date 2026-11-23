<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Normalizer;

use InvalidArgumentException;

/**
 * Stateful lookahead cursor over tokens produced by {@see ProtoTokenizer}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ProtoTokenCursor
{
    private int $offset = 0;

    private readonly int $count;

    /**
     * @param  list<array{0: 'ident'|'string'|'sym'|'number', 1: string|int}>  $tokens
     */
    public function __construct(
        private readonly array $tokens,
    ) {
        $this->count = count($tokens);
    }

    public function hasMore(): bool
    {
        return $this->offset < $this->count;
    }

    /**
     * @return array{0: 'ident'|'string'|'sym'|'number', 1: string|int}
     */
    public function peek(): array
    {
        return $this->tokens[$this->offset];
    }

    /**
     * @return array{0: 'ident'|'string'|'sym'|'number', 1: string|int}
     */
    public function consume(): array
    {
        return $this->tokens[$this->offset++];
    }

    public function peekWord(): ?string
    {
        $token = $this->tokens[$this->offset] ?? null;
        if ($token === null || $token[0] !== 'ident') {
            return null;
        }

        return (string) $token[1];
    }

    public function atSymbol(string $symbol): bool
    {
        $token = $this->tokens[$this->offset] ?? null;

        return $token !== null && $token[0] === 'sym' && $token[1] === $symbol;
    }

    public function expectWord(string $what): string
    {
        $word = $this->peekWord();
        if ($word === null) {
            throw new InvalidArgumentException("Expected {$what} in .proto source.");
        }
        $this->offset++;

        return $word;
    }

    public function expectSymbol(string $symbol): void
    {
        if (!$this->atSymbol($symbol)) {
            throw new InvalidArgumentException("Expected '{$symbol}' in .proto source.");
        }
        $this->offset++;
    }

    public function expectString(): string
    {
        $token = $this->tokens[$this->offset] ?? null;
        if ($token === null || $token[0] !== 'string') {
            throw new InvalidArgumentException('Expected a string literal in .proto source.');
        }
        $this->offset++;

        return (string) $token[1];
    }

    public function expectNumber(): int
    {
        $token = $this->tokens[$this->offset] ?? null;
        if ($token === null || $token[0] !== 'number') {
            throw new InvalidArgumentException('Expected a field number in .proto source.');
        }
        $this->offset++;

        return (int) $token[1];
    }
}
