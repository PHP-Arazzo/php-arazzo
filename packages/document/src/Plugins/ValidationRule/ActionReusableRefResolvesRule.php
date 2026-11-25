<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ActionReusableRefResolvesRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            $this->checkList($w->parameters->all(), 'parameters', $symbols, $errors, "/workflows/{$wi}/parameters");

            foreach ($w->steps as $si => $s) {
                $this->checkList($s->io->parameters, 'parameters', $symbols, $errors, "/workflows/{$wi}/steps/{$si}/parameters");
                $this->checkList($s->flow->onSuccess, 'successActions', $symbols, $errors, "/workflows/{$wi}/steps/{$si}/onSuccess");
                $this->checkList($s->flow->onFailure, 'failureActions', $symbols, $errors, "/workflows/{$wi}/steps/{$si}/onFailure");
            }
        }
    }

    /** @param array<mixed> $items */
    private function checkList(array $items, string $componentType, SymbolTable $symbols, ErrorCollector $errors, string $base): void
    {
        foreach ($items as $i => $item) {
            if (!$item instanceof Reusable) {
                continue;
            }
            $prefix = "\$components.{$componentType}.";
            if (!str_starts_with($item->reference, $prefix)) {
                $errors->error($this->code(), "Reusable reference '{$item->reference}' does not target components.{$componentType}.", "{$base}/{$i}/reference");

                continue;
            }
            $name = substr($item->reference, strlen($prefix));
            if (!isset($symbols->components[$componentType][$name])) {
                $errors->error($this->code(), "Reusable reference '{$item->reference}' does not resolve.", "{$base}/{$i}/reference");
            }
        }
    }

    public function code(): string
    {
        return 'action.reusable_ref_resolves';
    }
}
