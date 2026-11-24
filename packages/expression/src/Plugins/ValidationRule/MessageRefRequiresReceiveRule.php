<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
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

            $stepId = $site->currentStepId;
            $step = $stepId !== null
                ? array_find($doc->workflows, static fn ($wf): bool => $wf->findStep($stepId) !== null)?->findStep($stepId)
                : null;
            if ($step === null || !$step->isReceiveAction()) {
                $errors->error(
                    $this->code(),
                    'The $message runtime expression is only available in asyncapi steps with action "receive".',
                    $site->pointer,
                );
            }
        }
    }

    public function code(): string
    {
        return 'expr.message_requires_receive';
    }
}
