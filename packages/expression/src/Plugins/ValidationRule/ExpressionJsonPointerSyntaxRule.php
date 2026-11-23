<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Parser;
use Alama\Arazzo\Expression\Support\ExpressionWalker;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ExpressionJsonPointerSyntaxRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $ref = $this->parser->projectReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Step) {
                continue;
            }
            if ($ref->part !== 'request' && $ref->part !== 'response') {
                continue;
            }
            $ptr = $ref->jsonPointer;
            if ($ptr === null || $ptr === '') {
                continue;
            }

            $segments = explode('/', ltrim($ptr, '/'));
            foreach ($segments as $seg) {
                if (preg_match('/~(?![01])/', $seg) === 1) {
                    $errors->error($this->code(), "JSON Pointer '{$ptr}' contains illegal '~' escape.", $site->pointer);
                    break;
                }
            }
        }
    }

    public function code(): string
    {
        return 'expr.jsonpointer_syntax';
    }
}
