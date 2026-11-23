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
final class ExpressionUnresolvedInputRefRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $ref = $this->parser->projectReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Input) {
                continue;
            }

            $syms = $site->workflow;
            if ($syms === null) {
                continue;
            }
            if (!isset($syms->inputs[$ref->target]) && !isset($syms->parameters[$ref->target])) {
                $errors->error(
                    $this->code(),
                    "Expression references unknown input '{$ref->target}'.",
                    $site->pointer,
                );
            }
        }
    }

    public function code(): string
    {
        return 'expr.unresolved_input_ref';
    }
}
