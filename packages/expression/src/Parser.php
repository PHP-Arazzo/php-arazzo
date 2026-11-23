<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression;

use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\InputRef;
use Alama\Arazzo\Expression\Ast\OutputRef;
use Alama\Arazzo\Expression\Ast\RequestPart;
use Alama\Arazzo\Expression\Ast\ResponsePart;
use Alama\Arazzo\Expression\Ast\StepRef;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\TokenKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Internal\PointerParser;
use Alama\Arazzo\Expression\Internal\ReferenceTailParser;
use Alama\Arazzo\Expression\Internal\StepPartParser;

/**
 * Recursive-descent parser for the runtime-expression grammar.
 *
 * @internal stays out of the advertised contract; consumed by the ExpressionEngine facade.
 */
final class Parser implements ParserInterface
{
    private readonly PointerParser $pointers;

    private readonly ReferenceTailParser $references;

    private readonly StepPartParser $stepParts;

    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
    ) {
        $this->pointers = new PointerParser();
        $this->references = new ReferenceTailParser($this->pointers);
        $this->stepParts = new StepPartParser($this->pointers);
    }

    public function parse(string $raw): ExpressionAst
    {
        $tokens = $this->lexer->tokenize($raw);
        $i = ($tokens[0]->kind ?? null) === TokenKind::Dollar ? 1 : 0;
        if (!isset($tokens[$i])) {
            $this->pointers->fail("Expression ended after \$: {$raw}", $raw);
        }

        $head = $tokens[$i];
        if ($head->kind !== TokenKind::Keyword) {
            $this->pointers->fail("Expression must start with a keyword: {$raw}", $raw, $head);
        }

        return match ($head->value) {
            'inputs' => $this->references->parseSimpleRef($tokens, InputRef::class, $raw, $i),
            'outputs' => $this->references->parseSimpleRef($tokens, OutputRef::class, $raw, $i),
            'message' => $this->stepParts->parseMessageRef(array_slice($tokens, $i), $raw),
            'self' => $this->references->parseSelfRef($tokens, $raw, $i),
            'url', 'method', 'statusCode' => $this->references->parseHttpMeta($tokens, $raw, $i),
            'steps' => $this->stepParts->parseStepRef($tokens, $raw, $i),
            'workflows' => $this->references->parseWorkflowRef($tokens, $raw, $i),
            'sourceDescriptions' => $this->references->parseSourceRef($tokens, $raw, $i),
            'components' => $this->references->parseComponentRef($tokens, $raw, $i),
            'request', 'response' => new StepRef(
                null,
                $this->stepParts->parseHttpPart(
                    array_slice($tokens, $i + 1),
                    $head->value === 'request' ? RequestPart::class : ResponsePart::class,
                    $raw,
                ),
            ),
            default => $this->pointers->fail("Unknown root '{$head->value}' in expression: {$raw}", $raw, $head),
        };
    }

    public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
    {
        try {
            return $this->parse($raw);
        } catch (ExpressionSyntaxException $e) {
            return $e;
        }
    }

    public function projectReferences(string $raw): ?ExpressionReference
    {
        $result = $this->parseOrError($raw);

        if ($result instanceof ExpressionSyntaxException) {
            return null;
        }

        return $result->mapToExpressionReference();
    }
}
