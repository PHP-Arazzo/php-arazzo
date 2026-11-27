<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;

/**
 * Warns when an HTTP/OpenAPI operation step omits `successCriteria`.
 *
 * Authors SHOULD define explicit `successCriteria` on operation steps rather
 * than relying on implicit runner defaults (OAI/Arazzo-Specification #648).
 * Emits a warning by default so minimal spec-valid documents remain valid;
 * callers can promote it to an error or disable it via {@see ValidatorPolicy}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class HttpStepSuccessCriteriaRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $workflow) {
            foreach ($workflow->steps as $si => $step) {
                if (!$step->isHttpOperation() || $step->isAsyncAction() || $step->io->successCriteria !== []) {
                    continue;
                }

                $errors->warning(
                    $this->code(),
                    "HTTP operation step '{$step->stepId}' has no successCriteria; "
                    .'authors SHOULD define explicit successCriteria (e.g. $statusCode == 200).',
                    "/workflows/{$wi}/steps/{$si}/successCriteria",
                );
            }
        }
    }

    public function code(): string
    {
        return 'step.http_missing_success_criteria';
    }
}
