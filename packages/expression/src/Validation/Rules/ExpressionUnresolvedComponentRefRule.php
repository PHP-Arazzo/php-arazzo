<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Validation\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Expression\Validation\Support\ExpressionWalker;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class ExpressionUnresolvedComponentRefRule implements ValidationRuleInterface
{
    private readonly ExpressionEngineInterface $engine;

    public function __construct(?ExpressionEngineInterface $engine = null)
    {
        $this->engine = $engine ?? ExpressionEngine::create();
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ((new ExpressionWalker())->walk($doc, $symbols) as $site) {
            $ref = $this->engine->expressionReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Component) {
                continue;
            }

            $bag = $symbols->components[$ref->target] ?? null;
            if ($bag === null || !isset($bag[$ref->name])) {
                $errors->error($this->code(), "Component reference '{$ref->target}.{$ref->name}' is not declared.", $site->pointer);
            }
        }
    }

    public function code(): string
    {
        return 'expr.unresolved_component_ref';
    }
}
