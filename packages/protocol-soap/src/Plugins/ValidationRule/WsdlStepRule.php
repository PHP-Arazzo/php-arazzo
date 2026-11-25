<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;

/**
 * wsdl-step rules (Arazzo Specification, Step Object):
 *  - A step that references a `wsdl` source MUST use `operationName`, matching
 *    a `wsdl:operation` name. `operationId` and `operationPath` MUST NOT be
 *    used to reference a `wsdl` source.
 *  - When a step sets `operationName`, the resolved source MUST be of type
 *    `wsdl`, and the step MUST NOT also set `operationPath`.
 *
 * The JSON Schema cannot resolve the source type at validation time, so this
 * prose rule is enforced here (see the specification's characterization note).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class WsdlStepRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ?ExpressionEngineInterface $engine = null,
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wfIndex => $wf) {
            foreach ($wf->steps as $stepIndex => $step) {
                $this->checkStep($step, $wfIndex, $stepIndex, $doc, $errors);
            }
        }
    }

    private function checkStep(Step $step, int $wfIndex, int $stepIndex, ArazzoDocument $doc, ErrorCollector $errors): void
    {
        if ($step->operationReference() === null) {
            return;
        }

        $target = $step->target;
        $source = $this->resolveSource($target, $doc);
        $pointer = "/workflows/{$wfIndex}/steps/{$stepIndex}";

        if ($target->operationName !== null) {
            $this->checkOperationName($step, $target, $source, $pointer, $errors);

            return;
        }

        $this->checkNonNameReference($step, $target, $source, $pointer, $errors);
    }

    private function checkOperationName(
        Step $step,
        StepTarget $target,
        ?SourceDescription $source,
        string $pointer,
        ErrorCollector $errors,
    ): void {
        if ($source !== null && !$source->isWsdl()) {
            $errors->error(
                $this->code(),
                "Step '{$step->stepId}' uses operationName (wsdl) but references source '{$source->name}' of type {$source->type->value}. wsdl steps require a wsdl source.",
                $pointer,
            );
        }

        if ($target->operationPath !== null) {
            $errors->error(
                $this->code(),
                "Step '{$step->stepId}' uses operationName (wsdl) and also sets operationPath; wsdl steps must not set operationPath.",
                $pointer,
            );
        }
    }

    private function checkNonNameReference(
        Step $step,
        StepTarget $target,
        ?SourceDescription $source,
        string $pointer,
        ErrorCollector $errors,
    ): void {
        // operationId / operationPath MUST NOT reference a wsdl source.
        if ($source === null || !$source->isWsdl()) {
            return;
        }

        if ($target->operationId === null && $target->operationPath === null) {
            return;
        }

        $field = $target->operationId !== null ? 'operationId' : 'operationPath';
        $errors->error(
            $this->code(),
            "Step '{$step->stepId}' references source '{$source->name}' of type wsdl via {$field}; a wsdl source must be referenced with operationName, not {$field}.",
            $pointer,
        );
    }

    private function resolveSource(StepTarget $target, ArazzoDocument $doc): ?SourceDescription
    {
        $name = $this->qualifiedSourceName($target) ?? $this->operationPathSourceName($target, $doc);
        if ($name !== null) {
            return $doc->findSource($name);
        }

        // Fallback: a single non-arazzo source leaves no ambiguity.
        $nonArazzo = $doc->nonArazzoSources();

        return count($nonArazzo) === 1 ? $nonArazzo[0] : null;
    }

    private function qualifiedSourceName(StepTarget $target): ?string
    {
        // Qualified runtime-expression form: $sourceDescriptions.<name>.<ref>
        // (used by operationName and operationId).
        foreach ([$target->operationName, $target->operationId] as $ref) {
            if ($ref !== null && str_starts_with($ref, '$sourceDescriptions.')) {
                $parts = explode('.', $ref, 3);
                if (isset($parts[1]) && $parts[1] !== '') {
                    return $parts[1];
                }
            }
        }

        return null;
    }

    private function operationPathSourceName(StepTarget $target, ArazzoDocument $doc): ?string
    {
        if ($target->operationPath === null) {
            return null;
        }

        if ($this->engine === null) {
            return self::matchOperationPathSource($target->operationPath, $doc->specVersion);
        }

        try {
            $segments = $this->engine->parseEmbedded($target->operationPath, $doc->specVersion);
        } catch (ExpressionSyntaxException) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment->isLiteral()) {
                continue;
            }

            $reference = $this->engine->expressionReferences($segment->text);
            if ($reference !== null && $reference->isSourceUrl()) {
                return $reference->target;
            }
        }

        return null;
    }

    private static function matchOperationPathSource(string $operationPath, SpecVersion $specVersion): ?string
    {
        $pattern = $specVersion === SpecVersion::V1_0
            ? '/(?:\{\$|\$\{)sourceDescriptions\.([A-Za-z0-9_-]+)\.url\}/'
            : '/\{\$sourceDescriptions\.([A-Za-z0-9_-]+)\.url\}/';

        return preg_match($pattern, $operationPath, $matches) === 1 ? $matches[1] : null;
    }

    public function code(): string
    {
        return 'step.wsdl_step';
    }
}
