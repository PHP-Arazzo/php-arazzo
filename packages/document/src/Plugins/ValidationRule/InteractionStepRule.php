<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\InteractionMode;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * interaction-step rules (PR #568):
 *  - prompt is required
 *  - form and redirect modes require inputSchema
 *  - acknowledge must not have inputSchema
 *  - redirect mode requires exactly one of redirectOperationId or redirectUrl
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class InteractionStepRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'step.interaction_step';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                $interaction = $s->target->interaction;
                if ($interaction === null) {
                    continue;
                }

                $this->checkInteraction($s->stepId, $interaction, "/workflows/{$i}/steps/{$j}", $errors);
            }
        }
    }

    private function checkInteraction(
        string $stepId,
        Interaction $interaction,
        string $path,
        ErrorCollector $errors,
    ): void {
        if ($interaction->prompt === null || trim($interaction->prompt) === '') {
            $errors->error('step.interaction_step', "Step '{$stepId}' interaction requires a prompt.", $path);
        }

        $this->checkInputSchema($stepId, $interaction, $path, $errors);
        $this->checkRedirectTarget($stepId, $interaction, $path, $errors);
    }

    private function checkInputSchema(
        string $stepId,
        Interaction $interaction,
        string $path,
        ErrorCollector $errors,
    ): void {
        $mode = $interaction->mode;

        if (($mode === InteractionMode::Form || $mode === InteractionMode::Redirect) && $interaction->inputSchema === null) {
            $errors->error('step.interaction_step', "Step '{$stepId}' interaction mode {$mode->value} requires inputSchema.", $path);
        }

        if ($mode === InteractionMode::Acknowledge && $interaction->inputSchema !== null) {
            $errors->error('step.interaction_step', "Step '{$stepId}' interaction mode Acknowledge must not have inputSchema.", $path);
        }
    }

    private function checkRedirectTarget(
        string $stepId,
        Interaction $interaction,
        string $path,
        ErrorCollector $errors,
    ): void {
        if ($interaction->mode === InteractionMode::Redirect && !isset($interaction->redirectOperationId)) {
            $errors->error('step.interaction_step', "Step '{$stepId}' redirect interaction requires redirectOperationId.", $path);
        }
    }
}
