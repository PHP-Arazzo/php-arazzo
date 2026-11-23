<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\StepSymbols;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Data\ExpressionSite;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Parser;
use Alama\Arazzo\Expression\Support\ExpressionWalker;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ExpressionUnresolvedStepRefRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $workflow = $site->workflow;
            if ($workflow === null) {
                continue;
            }

            $ref = $this->parser->projectReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Step) {
                continue;
            }

            $this->checkReference($site, $workflow, $ref, $errors);
        }
    }

    private function checkReference(
        ExpressionSite $site,
        WorkflowSymbols $workflow,
        ExpressionReference $ref,
        ErrorCollector $errors,
    ): void {
        $targetStepId = $ref->target ?? $site->currentStepId;

        if ($targetStepId === null) {
            $errors->error($this->code(), 'Expression implicitly references current step but is used outside a step context.', $site->pointer);

            return;
        }

        $target = $workflow->stepsById[$targetStepId] ?? null;
        if ($target === null) {
            $errors->error($this->code(), "Expression references unknown step '{$targetStepId}'.", $site->pointer);

            return;
        }

        if ($this->isForwardReference($site, $workflow, $ref, $targetStepId, $target)) {
            $this->reportForwardReference($ref, $workflow, $site->pointer, $errors);

            return;
        }

        if ($ref->part === 'outputs' && $ref->name !== null && !isset($target->outputs[$ref->name])) {
            $errors->error($this->code(), "Step '{$ref->target}' does not declare output '{$ref->name}'.", $site->pointer);
        }
    }

    private function isForwardReference(
        ExpressionSite $site,
        WorkflowSymbols $workflow,
        ExpressionReference $ref,
        string $targetStepId,
        StepSymbols $target,
    ): bool {
        $currentStepId = $site->currentStepId;
        if ($currentStepId === null || !isset($workflow->stepsById[$currentStepId])) {
            return false;
        }

        return $ref->target !== null
            && $targetStepId !== $currentStepId
            && $target->index >= $workflow->stepsById[$currentStepId]->index;
    }

    private function reportForwardReference(
        ExpressionReference $ref,
        WorkflowSymbols $workflow,
        string $pointer,
        ErrorCollector $errors,
    ): void {
        $message = "Expression references step '{$ref->target}' which appears later in the steps array";

        // A reference to an earlier step is a valid implicit dependency
        // (Arazzo 1.1 "Tool Behavior"): the engine must order it before the
        // referencing step even without an explicit dependsOn entry.
        //
        // A forward reference is only statically unsatisfiable when the
        // workflow relies on pure sequential execution (no dependsOn used).
        // Otherwise the engine is expected to infer the implicit edge.
        if ($this->workflowUsesDependsOn($workflow)) {
            $errors->warning('expr.forward_step_ref', "{$message}; it forms an implicit dependency.", $pointer);

            return;
        }

        $errors->error($this->code(), "{$message}, and the workflow does not use dependsOn to make the ordering explicit.", $pointer);
    }

    public function code(): string
    {
        return 'expr.unresolved_step_ref';
    }

    private function workflowUsesDependsOn(WorkflowSymbols $syms): bool
    {
        foreach ($syms->stepsById as $step) {
            if ($step->dependsOn !== []) {
                return true;
            }
        }

        return false;
    }
}
