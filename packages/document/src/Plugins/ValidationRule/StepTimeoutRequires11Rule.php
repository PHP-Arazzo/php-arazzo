<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * The Step Object `timeout` field is an Arazzo 1.1 addition; it must not
 * appear on documents declaring 1.0.x, and its value must be positive.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepTimeoutRequires11Rule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                if ($s->flow->timeout === null) {
                    continue;
                }

                if ($doc->specVersion === SpecVersion::V1_0) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' declares timeout, which requires Arazzo 1.1.0.",
                        "/workflows/{$wi}/steps/{$si}/timeout",
                    );
                }

                if ($s->flow->timeout <= 0) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' timeout must be a positive number of seconds.",
                        "/workflows/{$wi}/steps/{$si}/timeout",
                    );
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.timeout_requires_11';
    }
}
