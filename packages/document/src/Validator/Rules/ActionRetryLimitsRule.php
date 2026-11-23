<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class ActionRetryLimitsRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                foreach ($s->flow->onFailure as $i => $a) {
                    if (!$a instanceof RetryAction) {
                        continue;
                    }
                    $base = "/workflows/{$wi}/steps/{$si}/onFailure/{$i}";
                    if ($a->retryAfter !== null && $a->retryAfter < 0) {
                        $errors->error($this->code(), 'retryAfter must be >= 0.', "{$base}/retryAfter");
                    }
                    if ($a->retryLimit !== null && $a->retryLimit < 0) {
                        $errors->error($this->code(), 'retryLimit must be >= 0.', "{$base}/retryLimit");
                    }
                }
            }
        }
    }

    public function code(): string
    {
        return 'action.retry_limits';
    }
}
