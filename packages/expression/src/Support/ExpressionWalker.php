<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Support;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;
use Alama\Arazzo\Expression\Data\ExpressionSite;
use Alama\Arazzo\Expression\Enum\ExpressionContext;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ExpressionWalker
{
    private ActionWalker $actionWalker;

    public function __construct(?ActionWalker $actionWalker = null)
    {
        $this->actionWalker = $actionWalker ?? new ActionWalker();
    }

    /**
     * @return iterable<ExpressionSite>
     */
    public function walk(ArazzoDocument $doc, SymbolTable $symbols): iterable
    {
        foreach ($doc->workflows as $wi => $wf) {
            $syms = $symbols->workflows[$wf->workflowId] ?? null;

            yield from $this->walkWorkflowParameters($wf, $wi, $syms);
            yield from $this->walkWorkflowOutputs($wf, $wi, $syms);
            yield from $this->walkWorkflowActions($wf, $wi, $syms);

            foreach ($wf->steps as $si => $s) {
                yield from $this->walkStep($s, $wi, $si, $syms);
            }
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkWorkflowParameters(Workflow $wf, int $wi, ?WorkflowSymbols $syms): iterable
    {
        foreach ($wf->parameters as $pi => $p) {
            if ($p instanceof Reusable) {
                continue;
            }

            yield from $this->extract($p->value, "/workflows/{$wi}/parameters/{$pi}/value", $syms, null, ExpressionContext::WorkflowParameters);
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkWorkflowOutputs(Workflow $wf, int $wi, ?WorkflowSymbols $syms): iterable
    {
        foreach ($wf->outputs as $name => $expr) {
            yield from $this->extract($expr, "/workflows/{$wi}/outputs/{$name}", $syms, null, ExpressionContext::WorkflowOutputs);
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkWorkflowActions(Workflow $wf, int $wi, ?WorkflowSymbols $syms): iterable
    {
        yield from $this->actionWalker->walk(
            $wf->successActions->all(),
            "/workflows/{$wi}/successActions",
            $syms,
            null,
            ExpressionContext::WorkflowSuccessActions,
        );
        yield from $this->actionWalker->walk(
            $wf->failureActions->all(),
            "/workflows/{$wi}/failureActions",
            $syms,
            null,
            ExpressionContext::WorkflowFailureActions,
        );
    }

    /** @return iterable<ExpressionSite> */
    private function walkStep(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        yield from $this->walkStepParameters($s, $wi, $si, $syms);
        yield from $this->walkRequestBody($s, $wi, $si, $syms);
        yield from $this->walkSuccessCriteria($s, $wi, $si, $syms);
        yield from $this->walkStepOutputs($s, $wi, $si, $syms);
        yield from $this->walkStepFlow($s, $wi, $si, $syms);
    }

    /** @return iterable<ExpressionSite> */
    private function walkStepParameters(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        foreach ($s->io->parameters as $pi => $p) {
            if ($p instanceof Reusable) {
                continue;
            }

            yield from $this->extract($p->value, "/workflows/{$wi}/steps/{$si}/parameters/{$pi}/value", $syms, $s->stepId, ExpressionContext::Parameters);
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkRequestBody(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        if ($s->io->requestBody === null) {
            return;
        }

        yield from $this->extract($s->io->requestBody->payload, "/workflows/{$wi}/steps/{$si}/requestBody/payload", $syms, $s->stepId, ExpressionContext::RequestBody);
        foreach ($s->io->requestBody->replacements as $ri => $r) {
            yield from $this->extract($r->value, "/workflows/{$wi}/steps/{$si}/requestBody/replacements/{$ri}/value", $syms, $s->stepId, ExpressionContext::RequestBody);
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkSuccessCriteria(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        yield from $this->actionWalker->walkCriteria(
            $s->io->successCriteria,
            "/workflows/{$wi}/steps/{$si}/successCriteria",
            $syms,
            $s->stepId,
            ExpressionContext::Criteria,
        );
    }

    /** @return iterable<ExpressionSite> */
    private function walkStepOutputs(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        foreach ($s->io->outputs as $name => $expr) {
            yield from $this->extract($expr, "/workflows/{$wi}/steps/{$si}/outputs/{$name}", $syms, $s->stepId, ExpressionContext::Outputs);
        }
    }

    /** @return iterable<ExpressionSite> */
    private function walkStepFlow(Step $s, int $wi, int $si, ?WorkflowSymbols $syms): iterable
    {
        $flows = [
            'onSuccess' => [$s->flow->onSuccess, ExpressionContext::OnSuccess],
            'onFailure' => [$s->flow->onFailure, ExpressionContext::OnFailure],
            'onTimeout' => [$s->flow->onTimeout, ExpressionContext::OnFailure],
            'onCancel' => [$s->flow->onCancel, ExpressionContext::OnFailure],
        ];

        foreach ($flows as $field => [$actions, $ctx]) {
            yield from $this->actionWalker->walk($actions, "/workflows/{$wi}/steps/{$si}/{$field}", $syms, $s->stepId, $ctx);
        }
    }

    /**
     * @return iterable<ExpressionSite>
     */
    private function extract(mixed $value, string $pointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        if ($value instanceof Expression) {
            yield new ExpressionSite($pointer, $value, $syms, $stepId, $context);
        } elseif ($value instanceof Selector && $value->context !== null && str_starts_with($value->context, '$')) {
            yield new ExpressionSite("{$pointer}/context", new Expression('{'.$value->context.'}'), $syms, $stepId, $context);
        }
    }
}
