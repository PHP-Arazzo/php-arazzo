<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Validation\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;

/**
 * Validates that every entry in `workflow.outputs` that is an `Expression`
 * parses to a valid AST and references only declared steps, workflows,
 * inputs, or outputs.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class ActionOutputTargetExclusivityRule implements ValidationRuleInterface
{
    private readonly ExpressionEngineInterface $expressionEngine;

    public function __construct(?ExpressionEngineInterface $expressionEngine = null)
    {
        $this->expressionEngine = $expressionEngine ?? ExpressionEngine::create();
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $declaredWorkflowIds = [];
        foreach ($doc->workflows as $w) {
            $declaredWorkflowIds[$w->workflowId] = true;
        }

        foreach ($doc->workflows as $wi => $w) {
            $syms = $symbols->workflows[$w->workflowId] ?? null;
            if ($syms === null) {
                continue;
            }

            foreach ($w->outputs as $name => $value) {
                if (!($value instanceof Expression)) {
                    continue; // null, scalar, or array – not an expression
                }

                $raw = $value->raw;
                $syntaxError = $this->expressionEngine->parseExpression($raw);
                if ($syntaxError !== null) {
                    $errors->error(
                        $this->code(),
                        "Output '{$name}': malformed expression '{$raw}': {$syntaxError->getMessage()}",
                        "/workflows/{$wi}/outputs/{$name}",
                    );

                    continue;
                }

                $reference = $this->expressionEngine->expressionReferences($raw);
                if ($reference === null) {
                    continue;
                }

                $error = $this->validateReference(
                    $reference,
                    $declaredWorkflowIds,
                    $syms,
                    $name,
                );
                if ($error !== null) {
                    $errors->error(
                        $this->code(),
                        $error->message,
                        "/workflows/{$wi}/outputs/{$name}",
                    );
                }
            }
        }
    }

    /**
     * @param  array<string,true>  $declaredWorkflowIds
     */
    private function validateReference(
        ExpressionReference $reference,
        array $declaredWorkflowIds,
        WorkflowSymbols $symbols,
        string $outputName,
    ): ?Error {
        return match ($reference->kind) {
            ReferenceKind::Step => $this->validateStepReference($reference, $symbols, $outputName),
            ReferenceKind::Workflow => $this->validateWorkflowReference($reference, $declaredWorkflowIds, $outputName),
            ReferenceKind::Input => $this->validateInputReference($reference, $symbols, $outputName),
            ReferenceKind::Output => $this->validateOutputReference($reference, $symbols, $outputName),
            default => null, // Source, Component, Message, Self, HttpMeta are unconstrained
        };
    }

    private function validateStepReference(
        ExpressionReference $reference,
        WorkflowSymbols $symbols,
        string $outputName,
    ): ?Error {
        $stepId = $reference->target;
        if ($stepId === null) {
            return null; // current step – always valid
        }

        if (!isset($symbols->stepsById[$stepId])) {
            return new Error(
                $this->code(),
                "Output '{$outputName}': step '{$stepId}' is not declared.",
                "/outputs/{$outputName}",
            );
        }

        return null;
    }

    /**
     * @param  array<string,true>  $declaredWorkflowIds
     */
    private function validateWorkflowReference(
        ExpressionReference $reference,
        array $declaredWorkflowIds,
        string $outputName,
    ): ?Error {
        $workflowId = $reference->target;
        if ($workflowId === null) {
            return null;
        }

        if (!isset($declaredWorkflowIds[$workflowId])) {
            return new Error(
                $this->code(),
                "Output '{$outputName}': workflow '{$workflowId}' is not declared.",
                "/outputs/{$outputName}",
            );
        }

        return null;
    }

    private function validateInputReference(
        ExpressionReference $reference,
        WorkflowSymbols $symbols,
        string $outputName,
    ): ?Error {
        $inputName = $reference->target;
        if ($inputName === null) {
            return null;
        }

        if (!isset($symbols->inputs[$inputName])) {
            return new Error(
                $this->code(),
                "Output '{$outputName}': input '{$inputName}' is not declared.",
                "/outputs/{$outputName}",
            );
        }

        return null;
    }

    private function validateOutputReference(
        ExpressionReference $reference,
        WorkflowSymbols $symbols,
        string $outputName,
    ): ?Error {
        $declaredOutputName = $reference->target;
        if ($declaredOutputName === null) {
            return null;
        }

        if (!isset($symbols->outputs[$declaredOutputName])) {
            return new Error(
                $this->code(),
                "Output '{$outputName}': output '{$declaredOutputName}' is not declared.",
                "/outputs/{$outputName}",
            );
        }

        return null;
    }

    public function code(): string
    {
        return 'action.output_target_ambiguous';
    }
}
