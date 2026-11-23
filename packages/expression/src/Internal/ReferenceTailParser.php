<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Internal;

use Alama\Arazzo\Expression\Ast\ComponentRef;
use Alama\Arazzo\Expression\Ast\HttpMetaRef;
use Alama\Arazzo\Expression\Ast\InputRef;
use Alama\Arazzo\Expression\Ast\OutputRef;
use Alama\Arazzo\Expression\Ast\SelfRef;
use Alama\Arazzo\Expression\Ast\SourceRef;
use Alama\Arazzo\Expression\Ast\WorkflowRef;
use Alama\Arazzo\Expression\Data\Token;
use Alama\Arazzo\Expression\Enum\TokenKind;

/**
 * Parses top-level non-step reference productions (`$inputs`, `$outputs`, `$self`,
 * `$url`/`$method`/`$statusCode`, `$workflows`, `$sourceDescriptions`, `$components`).
 *
 * @internal stays out of the advertised contract; consumed by Parser.
 */
final readonly class ReferenceTailParser
{
    public function __construct(
        private PointerParser $pointers = new PointerParser(),
    ) {}

    /**
     * @param  list<Token>  $tokens
     * @param  class-string<InputRef|OutputRef>  $refClass
     */
    public function parseSimpleRef(array $tokens, string $refClass, string $raw, int $offset): InputRef|OutputRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) < 3 || $rest[1]->kind !== TokenKind::Dot || !$this->pointers->isIdentifierToken($rest[2])) {
            $this->pointers->fail("Malformed reference: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Name, TokenKind::Keyword],
            ]));
        }

        if (count($rest) > 3) {
            if ($rest[3]->kind === TokenKind::Hash) {
                return new $refClass($rest[2]->value, $this->pointers->parseJsonPointer(array_slice($rest, 3), $raw));
            }

            $this->pointers->fail("Malformed reference: {$raw}", $raw, $rest[3]);
        }

        return new $refClass($rest[2]->value);
    }

    /** @param list<Token> $tokens */
    public function parseSelfRef(array $tokens, string $raw, int $offset): SelfRef
    {
        if (count(array_slice($tokens, $offset)) !== 1) {
            $this->pointers->fail("Malformed \$self reference: {$raw}", $raw, $tokens[$offset + 1]);
        }

        return new SelfRef();
    }

    /** @param list<Token> $tokens */
    public function parseHttpMeta(array $tokens, string $raw, int $offset): HttpMetaRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) !== 1) {
            $this->pointers->fail("Malformed HTTP meta reference: {$raw}", $raw, $rest[1] ?? null);
        }
        /** @var 'url'|'method'|'statusCode' $field */
        $field = $rest[0]->value;

        return new HttpMetaRef($field);
    }

    /** @param list<Token> $tokens */
    public function parseWorkflowRef(array $tokens, string $raw, int $offset): WorkflowRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) !== 7) {
            $at = $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Name, TokenKind::Keyword],
                3 => [TokenKind::Dot],
                4 => [TokenKind::Keyword],
                5 => [TokenKind::Dot],
                6 => [TokenKind::Name, TokenKind::Keyword],
            ]);
            if ($at === null && isset($rest[7])) {
                $at = $rest[7];
            }
            $this->pointers->fail("Malformed workflow reference: {$raw}", $raw, $at);
        }

        $kind = $rest[4]->value;
        if ($kind !== 'inputs' && $kind !== 'outputs') {
            $this->pointers->fail("Workflow part must be inputs or outputs in: {$raw}", $raw, $rest[4]);
        }

        /** @var 'inputs'|'outputs' $kind */
        return new WorkflowRef($rest[2]->value, $kind, $rest[6]->value);
    }

    /** @param list<Token> $tokens */
    public function parseSourceRef(array $tokens, string $raw, int $offset): SourceRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) < 3 || $rest[1]->kind !== TokenKind::Dot || !$this->pointers->isIdentifierToken($rest[2])) {
            $this->pointers->fail("Malformed sourceDescriptions reference: {$raw}", $raw, $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Name, TokenKind::Keyword],
            ]));
        }

        $name = $rest[2]->value;
        if (count($rest) === 3) {
            return new SourceRef($name, null);
        }
        if ($rest[3]->kind !== TokenKind::Dot) {
            $this->pointers->fail("Expected '.' after source name in: {$raw}", $raw, $rest[3]);
        }

        $out = '';
        foreach (array_slice($rest, 4) as $t) {
            $out .= $t->kind === TokenKind::Dot ? '.' : $t->value;
        }

        return new SourceRef($name, $out === '' ? null : $out);
    }

    /** @param list<Token> $tokens */
    public function parseComponentRef(array $tokens, string $raw, int $offset): ComponentRef
    {
        $rest = array_slice($tokens, $offset);
        if (count($rest) !== 5
            || $rest[1]->kind !== TokenKind::Dot
            || !$this->pointers->isIdentifierToken($rest[2])
            || $rest[3]->kind !== TokenKind::Dot
            || !$this->pointers->isIdentifierToken($rest[4])) {
            $at = $this->pointers->firstUnexpected($rest, [
                1 => [TokenKind::Dot],
                2 => [TokenKind::Name, TokenKind::Keyword],
                3 => [TokenKind::Dot],
                4 => [TokenKind::Name, TokenKind::Keyword],
            ]);
            if ($at === null && isset($rest[5])) {
                $at = $rest[5];
            }
            $this->pointers->fail("Malformed components reference: {$raw}", $raw, $at);
        }

        return new ComponentRef($rest[2]->value, $rest[4]->value);
    }
}
