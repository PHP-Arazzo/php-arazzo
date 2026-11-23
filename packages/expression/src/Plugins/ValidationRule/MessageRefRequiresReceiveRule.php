<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Parser;
use Alama\Arazzo\Expression\Support\ExpressionWalker;

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
#[AsValidationRulePlugin]
final class MessageRefRequiresReceiveRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $ref = $this->parser->projectReferences($site->expression->raw);
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
