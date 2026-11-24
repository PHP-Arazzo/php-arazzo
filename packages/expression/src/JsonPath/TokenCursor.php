<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\JsonPath;

use Alama\Arazzo\Expression\Exceptions\JsonPathSyntaxException;

/**
 * Mutable token stream cursor shared between JSONPath selector and filter parsers.
 *
 * @internal stays out of the advertised contract; consumed by JsonPath\Parser.
 */
final class TokenCursor
{
    /** @var list<array{string, mixed}> */
    private array $tokens;

    private int $pos = 0;

    private int $tokenCount;

    /**
     * @param  list<array{string, mixed}>  $tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->tokenCount = count($tokens);
    }

    public function hasMore(): bool
    {
        return $this->pos < $this->tokenCount;
    }

    public function isType(string $type): bool
    {
        return $this->pos < $this->tokenCount && $this->tokens[$this->pos][0] === $type;
    }

    public function isValue(string $value): bool
    {
        return $this->pos < $this->tokenCount && $this->tokens[$this->pos][1] === $value;
    }

    /**
     * @return array{string, mixed}
     */
    public function current(): array
    {
        if ($this->pos >= $this->tokenCount) {
            throw JsonPathSyntaxException::unexpectedEnd();
        }

        return $this->tokens[$this->pos];
    }

    public function advance(): void
    {
        $this->pos++;
    }

    public function expect(string $type, ?string $value = null): void
    {
        $token = $this->current();
        if ($token[0] !== $type) {
            throw JsonPathSyntaxException::expectedTokenType($type, $token[0]);
        }

        if ($value !== null && $token[1] !== $value) {
            throw JsonPathSyntaxException::expectedTokenValue($type, $value, $token[1]);
        }

        $this->pos++;
    }
}
