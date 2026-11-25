<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Normalizer;

/**
 * Lexical scanner for `.proto` source files.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ProtoTokenizer
{
    /**
     * @return list<array{0: 'ident'|'string'|'sym'|'number', 1: string|int}>
     */
    public static function tokenize(string $content): array
    {
        $tokens = [];
        $length = strlen($content);
        $i = 0;

        while ($i < $length) {
            $char = $content[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if ($char === '/') {
                $afterComment = self::skipComment($content, $i);
                if ($afterComment !== null) {
                    $i = $afterComment;

                    continue;
                }
            }

            if ($char === '"' || $char === "'") {
                $quoted = self::scanQuoted($content, $i);
                $tokens[] = $quoted[0];
                $i = $quoted[1];

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                [$word, $i] = self::scanWord($content, $i);
                $tokens[] = ['ident', $word];

                continue;
            }

            if (ctype_digit($char)) {
                [$digits, $i] = self::scanWord($content, $i);
                $tokens[] = ['number', (int) $digits];

                continue;
            }

            $tokens[] = ['sym', $char];
            $i++;
        }

        return $tokens;
    }

    /**
     * Advances past a line (`//`) or block comment starting at $i, or returns
     * null when the slash is not a comment opener.
     */
    private static function skipComment(string $content, int $i): ?int
    {
        $next = $content[$i + 1] ?? '';

        if ($next === '/') {
            $break = strpos($content, "\n", $i + 2);

            return $break === false ? strlen($content) : $break + 1;
        }

        if ($next === '*') {
            $break = strpos($content, '*/', $i + 2);

            return $break === false ? strlen($content) : $break + 2;
        }

        return null;
    }

    /**
     * @return array{0: array{0: 'string', 1: string}, 1: int}
     */
    private static function scanQuoted(string $content, int $i): array
    {
        $quote = $content[$i];
        $start = ++$i;
        $length = strlen($content);

        while ($i < $length && $content[$i] !== $quote) {
            $i++;
        }

        return [['string', substr($content, $start, $i - $start)], min($i + 1, $length)];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function scanWord(string $content, int $i): array
    {
        $start = $i;
        $length = strlen($content);

        while ($i < $length && (ctype_alnum($content[$i]) || $content[$i] === '_')) {
            $i++;
        }

        return [substr($content, $start, $i - $start), $i];
    }
}
