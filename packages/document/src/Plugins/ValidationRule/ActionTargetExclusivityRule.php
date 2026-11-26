<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ActionTargetExclusivityRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            $this->checkList($w->successActions->all(), $errors, "/workflows/{$wi}/successActions");
            $this->checkList($w->failureActions->all(), $errors, "/workflows/{$wi}/failureActions");

            foreach ($w->steps as $si => $s) {
                $this->checkList($s->flow->onSuccess, $errors, "/workflows/{$wi}/steps/{$si}/onSuccess");
                $this->checkList($s->flow->onFailure, $errors, "/workflows/{$wi}/steps/{$si}/onFailure");
            }
        }
    }

    /** @param list<mixed> $actions */
    private function checkList(array $actions, ErrorCollector $errors, string $base): void
    {
        foreach ($actions as $i => $a) {
            if (!($a instanceof SuccessGotoAction || $a instanceof FailureGotoAction || $a instanceof RetryAction)) {
                continue;
            }
            if ($a->stepId !== null && $a->workflowId !== null) {
                $errors->error(
                    $this->code(),
                    "Action '{$a->name}' declares both stepId and workflowId; they are mutually exclusive.",
                    "{$base}/{$i}",
                );
            }
        }
    }

    public function code(): string
    {
        return 'action.target_ambiguous';
    }
}
