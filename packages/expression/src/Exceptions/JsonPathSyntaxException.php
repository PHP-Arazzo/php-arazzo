<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

final class JsonPathSyntaxException extends ArazzoException
{
    public static function unexpectedTrailingTokens(): self
    {
        return new self('Unexpected trailing tokens in JSONPath expression', '', 'jsonpath.syntax');
    }

    public static function expectedMemberAfterDot(): self
    {
        return new self('Expected member name after dot', '', 'jsonpath.syntax');
    }

    public static function expectedMemberAfterDescent(): self
    {
        return new self('Expected member name after ..', '', 'jsonpath.syntax');
    }

    public static function unsupportedBracketSelector(): self
    {
        return new self('Unsupported bracket selector', '', 'jsonpath.syntax');
    }

    public static function unexpectedFilterToken(mixed $value): self
    {
        return new self('Unexpected token in filter expression: '.var_export($value, true), '', 'jsonpath.syntax');
    }

    public static function expectedFieldAfterCurrent(): self
    {
        return new self('Expected field name after @.', '', 'jsonpath.syntax');
    }

    public static function expectedFieldInPath(): self
    {
        return new self('Expected field name in path expression', '', 'jsonpath.syntax');
    }

    public static function unexpectedEnd(): self
    {
        return new self('Unexpected end of JSONPath expression', '', 'jsonpath.syntax');
    }

    public static function expectedTokenType(string $expected, mixed $actual): self
    {
        return new self("Expected token {$expected}, got ".var_export($actual, true), '', 'jsonpath.syntax');
    }

    public static function expectedTokenValue(string $type, string $expected, mixed $actual): self
    {
        return new self("Expected token {$type} '{$expected}', got ".var_export($actual, true), '', 'jsonpath.syntax');
    }
}
