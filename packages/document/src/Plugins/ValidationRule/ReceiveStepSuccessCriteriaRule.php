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
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ReceiveStepSuccessCriteriaRule implements ValidationRuleInterface
{
    public function __construct(public readonly bool $strict = true) {}

    /**
     * Arazzo 1.1 §5.8.5.3 says authors SHOULD define successCriteria for
     * receive steps, but MAY omit them when the channel's single message type
     * unambiguously signals success. Default auto-discovered instance warns.
     */
    public static function create(): self
    {
        return new self(false);
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        // AsyncAPI fields (and therefore receive steps) require 1.1.0+, so a
        // 1.0 document can never legally declare one; leave that report to
        // AsyncApiFieldsRequire11Rule instead of duplicating it here.
        if ($doc->specVersion === SpecVersion::V1_0) {
            return;
        }

        foreach ($doc->workflows as $wi => $workflow) {
            foreach ($workflow->steps as $si => $step) {
                if (!$step->isReceiveAction() || $step->io->successCriteria !== []) {
                    continue;
                }

                $message = "receive step '{$step->stepId}' has no successCriteria; "
                    .'the default only requires a message to arrive.';
                $path = "/workflows/{$wi}/steps/{$si}/successCriteria";

                if ($this->strict) {
                    $errors->error($this->code(), $message, $path);
                } else {
                    $errors->warning($this->code(), $message, $path);
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.receive_missing_success_criteria';
    }
}
