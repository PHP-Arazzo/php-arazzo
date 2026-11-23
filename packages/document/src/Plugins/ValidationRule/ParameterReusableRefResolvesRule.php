<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Validates that reusable parameter references (`$components.parameters.*`)
 * declared in workflow, step and Success/Failure Action parameter lists
 * resolve to a defined `components.parameters` entry.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ParameterReusableRefResolvesRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            // Workflow-level reusable parameters.
            $this->checkList($w->parameters, $symbols, $errors, "/workflows/{$wi}/parameters");

            // Workflow-level Success/Failure Action parameters.
            $this->checkActionParameters($w->successActions, $symbols, $errors, "/workflows/{$wi}/successActions");
            $this->checkActionParameters($w->failureActions, $symbols, $errors, "/workflows/{$wi}/failureActions");

            foreach ($w->steps as $si => $s) {
                // Step-level reusable parameters.
                $this->checkList($s->io->parameters, $symbols, $errors, "/workflows/{$wi}/steps/{$si}/parameters");

                // Step-level Success/Failure Action parameters.
                $this->checkActionParameters($s->flow->onSuccess, $symbols, $errors, "/workflows/{$wi}/steps/{$si}/onSuccess");
                $this->checkActionParameters($s->flow->onFailure, $symbols, $errors, "/workflows/{$wi}/steps/{$si}/onFailure");
            }
        }
    }

    /**
     * @param  list<Parameter|Reusable>  $params
     */
    private function checkList(array $params, SymbolTable $symbols, ErrorCollector $errors, string $base): void
    {
        foreach ($params as $pi => $p) {
            if (!$p instanceof Reusable) {
                continue;
            }
            $this->checkReusable($p, $symbols, $errors, "{$base}/{$pi}/reference");
        }
    }

    /**
     * @param  list<mixed>  $actions
     */
    private function checkActionParameters(array $actions, SymbolTable $symbols, ErrorCollector $errors, string $base): void
    {
        foreach ($actions as $ai => $action) {
            if (!$action instanceof SuccessGotoAction && !$action instanceof FailureGotoAction) {
                continue;
            }
            /** @var list<Parameter|Reusable> $parameters */
            $parameters = $action->parameters;
            foreach ($parameters as $pi => $p) {
                if (!$p instanceof Reusable) {
                    continue;
                }
                $this->checkReusable($p, $symbols, $errors, "{$base}/{$ai}/parameters/{$pi}/reference");
            }
        }
    }

    private function checkReusable(Reusable $r, SymbolTable $symbols, ErrorCollector $errors, string $path): void
    {
        $prefix = '$components.parameters.';
        if (!str_starts_with($r->reference, $prefix)) {
            $errors->error(
                $this->code(),
                "Reusable parameter reference '{$r->reference}' does not target components.parameters.",
                $path,
            );

            return;
        }

        $name = substr($r->reference, strlen($prefix));
        if (!isset($symbols->components['parameters'][$name])) {
            $errors->error(
                $this->code(),
                "Reusable parameter reference '{$r->reference}' does not resolve.",
                $path,
            );
        }
    }

    public function code(): string
    {
        return 'parameter.reusable_ref_resolves';
    }
}
