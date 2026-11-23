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
final class ExpressionUnresolvedWorkflowRefRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $ref = $this->parser->projectReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Workflow) {
                continue;
            }

            $target = $symbols->workflows[$ref->target] ?? null;
            if ($target === null) {
                $errors->error($this->code(), "Expression references unknown workflow '{$ref->target}'.", $site->pointer);

                continue;
            }
            if ($site->workflow !== null && !isset($site->workflow->dependsOn[$ref->target])) {
                $errors->error($this->code(), "Expression references workflow '{$ref->target}' which is not in dependsOn.", $site->pointer);

                continue;
            }
            $bag = $ref->part === 'inputs' ? $target->inputs : $target->outputs;
            if (!isset($bag[$ref->name])) {
                $errors->error($this->code(), "Workflow '{$ref->target}' has no {$ref->part}.{$ref->name}.", $site->pointer);
            }
        }
    }

    public function code(): string
    {
        return 'expr.unresolved_workflow_ref';
    }
}
