<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Internal;

use Alama\Arazzo\Expression\Ast\InputPart;
use Alama\Arazzo\Expression\Ast\MessageRef;
use Alama\Arazzo\Expression\Ast\OutputPart;
use Alama\Arazzo\Expression\Ast\RequestPart;
use Alama\Arazzo\Expression\Ast\ResponsePart;
use Alama\Arazzo\Expression\Ast\StepRef;
use Alama\Arazzo\Expression\Data\Token;
use Alama\Arazzo\Expression\Enum\TokenKind;

/**
 * Parses `$steps.*`, `$request.*`, `$response.*`, and `$message.*` productions.
 *
 * @internal stays out of the advertised contract; consumed by Parser.
 */
final readonly class StepPartParser
{
    public function __construct(
        private PointerParser $pointers = new PointerParser(),
    ) {}

    /** @param list<Token> $tokens */
    public function parseStepRef(array $tokens, string $raw, int $offset): StepRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) < 5
            || !$rest[1]->isKind(TokenKind::Dot)
            || !$rest[2]->isIdentifier()
            || !$rest[3]->isKind(TokenKind::Dot)
            || !$rest[4]->isIdentifier()) {
            $this->pointers->fail("Malformed step reference: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Name, TokenKind::Keyword],
                3 => [TokenKind::Dot],
                4 => [TokenKind::Name, TokenKind::Keyword],
            ]));
        }

        $stepId = $rest[2]->value;
        $sub = $rest[4]->value;
        $tail = array_slice($rest, 5);

        return new StepRef($stepId, match ($sub) {
            'outputs' => $tail === [] ? new OutputPart(null) : $this->parseNamedPart($tail, OutputPart::class, $raw, allowPointer: true),
            'inputs' => $this->parseNamedPart($tail, InputPart::class, $raw),
            'request' => $this->parseHttpPart($tail, RequestPart::class, $raw),
            'response' => $this->parseHttpPart($tail, ResponsePart::class, $raw),
            default => $this->parseOutputShortcut($tail, $sub, $raw),
        });
    }

    /** @param list<Token> $rest */
    public function parseMessageRef(array $rest, string $raw): MessageRef
    {
        if (count($rest) < 3
            || !$rest[1]->isKind(TokenKind::Dot)
            || !$rest[2]->isKind(TokenKind::Keyword)) {
            $this->pointers->fail("Malformed message reference: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Keyword],
            ]));
        }

        $part = $rest[2]->value;
        $tail = array_slice($rest, 3);

        if ($part === 'header') {
            return new MessageRef('header', $this->pointers->parseHeaderName($tail, $raw), null);
        }

        if ($part === 'payload') {
            return new MessageRef('payload', null, $this->pointers->parseJsonPointer($tail, $raw));
        }

        $this->pointers->fail("Message part must be header or payload in: {$raw}", $raw, $rest[2]);
    }

    /**
     * @param  list<Token>  $rest
     * @param  class-string<RequestPart|ResponsePart>  $cls
     */
    public function parseHttpPart(array $rest, string $cls, string $raw): RequestPart|ResponsePart
    {
        if ($rest === []) {
            return new $cls(null, null, null);
        }
        if (!$rest[0]->isKind(TokenKind::Dot)) {
            $this->pointers->fail("Expected '.' after part in: {$raw}", $raw, $rest[0]);
        }
        if (count($rest) < 2 || !$rest[1]->isKind(TokenKind::Keyword)) {
            $this->pointers->fail("Expected keyword after '.' in: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Keyword],
            ]));
        }

        $kw = $rest[1]->value;
        $tail = array_slice($rest, 2);

        return match ($kw) {
            'body' => new $cls('body', null, $this->pointers->parseJsonPointer($tail, $raw)),
            'header', 'query', 'path' => new $cls($kw, $this->pointers->parseHeaderName($tail, $raw), null),
            'url', 'method', 'statusCode' => $this->parseTerminalHttpPart($cls, $kw, $tail, $raw),
            default => $this->pointers->fail("Unknown http part '{$kw}' in: {$raw}", $raw, $rest[1]),
        };
    }

    /**
     * @param  class-string<RequestPart|ResponsePart>  $cls
     * @param  list<Token>  $tail
     */
    private function parseTerminalHttpPart(string $cls, string $kw, array $tail, string $raw): RequestPart|ResponsePart
    {
        if ($tail !== []) {
            $this->pointers->fail("Unexpected tokens after '{$kw}' in: {$raw}", $raw, $tail[0]);
        }

        return new $cls($kw, null, null);
    }

    /** @param list<Token> $tail */
    private function parseOutputShortcut(array $tail, string $name, string $raw): OutputPart
    {
        if ($tail === []) {
            return new OutputPart($name);
        }

        if ($tail[0]->isKind(TokenKind::Hash)) {
            return new OutputPart($name, $this->pointers->parseJsonPointer($tail, $raw));
        }

        $this->pointers->fail("Malformed output reference after step name in: {$raw}", $raw, $tail[0]);
    }

    /**
     * @param  list<Token>  $rest
     * @param  class-string<OutputPart|InputPart>  $cls
     */
    private function parseNamedPart(array $rest, string $cls, string $raw, bool $allowPointer = false): OutputPart|InputPart
    {
        if (count($rest) < 2 || !$rest[0]->isKind(TokenKind::Dot) || !$rest[1]->isIdentifier()) {
            $this->pointers->fail("Malformed reference: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                0 => [TokenKind::Dot],
                1 => [TokenKind::Name, TokenKind::Keyword],
            ]));
        }

        if ($allowPointer && count($rest) > 2 && $cls === OutputPart::class) {
            return $this->parseOutputWithSuffix($rest, $raw);
        }

        if (count($rest) !== 2) {
            $this->pointers->fail("Malformed reference: {$raw}", $raw, $rest[2] ?? null);
        }

        return new $cls($rest[1]->value);
    }

    /** @param list<Token> $rest */
    private function parseOutputWithSuffix(array $rest, string $raw): OutputPart
    {
        if ($rest[2]->isKind(TokenKind::Hash)) {
            return new OutputPart($rest[1]->value, $this->pointers->parseJsonPointer(array_slice($rest, 2), $raw));
        }

        if ($rest[2]->isKind(TokenKind::LeftBracket)) {
            return new OutputPart($rest[1]->value, $this->pointers->parseArrayIndexPointer(array_slice($rest, 2), $raw));
        }

        $this->pointers->fail("Unexpected tokens after '{$rest[1]->value}' in: {$raw}", $raw, $rest[2]);
    }
}
