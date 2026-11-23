<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Validation\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Expression\Validation\Support\ExpressionWalker;

/**
 * Flags the `$message` runtime expression root when it is used outside an
 * AsyncAPI `action: receive` step.
 *
 * Per Arazzo 1.1.0 §5.9, `$message` is available only in steps with kind
 * asyncapi and action `receive`. The expression engine already parses and
 * resolves `$message.header.*` / `$message.payload[#/ptr]`; this rule adds the
 * document-level context check that the engine cannot perform on its own.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class MessageRefRequiresReceiveRule implements ValidationRuleInterface
{
    private readonly ExpressionEngineInterface $engine;

    public function __construct(?ExpressionEngineInterface $engine = null)
    {
        $this->engine = $engine ?? ExpressionEngine::create();
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ((new ExpressionWalker())->walk($doc, $symbols) as $site) {
            $ref = $this->engine->expressionReferences($site->expression->raw);
            if ($ref === null || $ref->kind !== ReferenceKind::Message) {
                continue;
            }

            $step = $this->findStep($doc, $site->currentStepId);
            if ($step === null || $step->target->action !== 'receive') {
                $errors->error(
                    $this->code(),
                    'The $message runtime expression is only available in asyncapi steps with action "receive".',
                    $site->pointer,
                );
            }
        }
    }

    /**
     * Locate the step carrying the given stepId across all workflows.
     */
    private function findStep(ArazzoDocument $doc, ?string $stepId): ?Step
    {
        if ($stepId === null) {
            return null;
        }

        foreach ($doc->workflows as $wf) {
            foreach ($wf->steps as $s) {
                if ($s->stepId === $stepId) {
                    return $s;
                }
            }
        }

        return null;
    }

    public function code(): string
    {
        return 'expr.message_requires_receive';
    }
}
