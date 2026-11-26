<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Rejects duplicate replacement targets within a step's requestBody.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepRequestBodyReplacementsDuplicateRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                if ($s->io->requestBody === null) {
                    continue;
                }

                $seen = [];
                foreach ($s->io->requestBody->replacements as $k => $r) {
                    $target = $r->target;
                    if (isset($seen[$target])) {
                        $errors->error(
                            $this->code(),
                            "Duplicate replacement target '{$target}' in requestBody of step '{$s->stepId}' at index {$k} (previously defined at index {$seen[$target]}).",
                            "/workflows/{$wi}/steps/{$si}/requestBody/replacements/{$k}/target",
                        );
                    } else {
                        $seen[$target] = $k;
                    }
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.request_body_replacements_duplicate_target';
    }
}
