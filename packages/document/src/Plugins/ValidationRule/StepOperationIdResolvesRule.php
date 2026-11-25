<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Resolves an operationId against the operations actually declared in its
 * referenced source description.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepOperationIdResolvesRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $singleNonArazoSource = $this->singleNonArazoSource($doc);

        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                $operationId = $s->target->operationId;
                if ($operationId === null) {
                    continue;
                }

                [$sourceName, $operation] = $this->parse($operationId, $singleNonArazoSource);

                if ($sourceName === null) {
                    // Cannot determine a source; qualification gate is handled
                    // by StepOperationIdSourceScopedRule.
                    continue;
                }

                if (!isset($symbols->sourceDescriptions[$sourceName])) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' uses unknown source qualifier '{$sourceName}' in operationId '{$operationId}'.",
                        "/workflows/{$wi}/steps/{$si}/operationId",
                    );

                    continue;
                }

                if (!$symbols->knownOperations->covers($sourceName)) {
                    // No resolved operations available (unreachable/skipped
                    // source per ADR-005): skip the existence check.
                    continue;
                }

                if (!$symbols->knownOperations->has($sourceName, $operation)) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' uses unresolved operationId '{$operationId}'.",
                        "/workflows/{$wi}/steps/{$si}/operationId",
                    );
                }
            }
        }
    }

    /**
     * @return array{0:?string,1:string}
     */
    private function parse(string $operationId, ?string $singleNonArazoSource): array
    {
        $candidate = $operationId;
        if (str_starts_with($candidate, '$sourceDescriptions.')) {
            $candidate = substr($candidate, strlen('$sourceDescriptions.'));
        }

        if (str_contains($candidate, '.')) {
            $parts = explode('.', $candidate, 2);

            return [$parts[0], $parts[1]];
        }

        // Unqualified: only meaningful when a single non-arazo source exists.
        return [$singleNonArazoSource, $operationId];
    }

    private function singleNonArazoSource(ArazzoDocument $doc): ?string
    {
        $sources = $doc->nonArazzoSources();

        return count($sources) === 1 ? $sources[0]->name : null;
    }

    public function code(): string
    {
        return 'step.operationid_resolves';
    }
}
