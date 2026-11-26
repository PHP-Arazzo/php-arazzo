<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Support;

use Alama\Arazzo\Contracts\Spec\Action\Action;
use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
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
    private const string RUNTIME_EXPR_PATTERN = '/\{\$([^}]+)\}|\$\{([^}]+)\}|\$(?:inputs|outputs|steps|workflows|sourceDescriptions|components|response|request|url|method|statusCode|message|self)(?:[A-Za-z0-9_\-\.#~%@+:\/]+|\b)/';

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
        yield from $this->walkActions(
            $wf->successActions->all(),
            "/workflows/{$wi}/successActions",
            $syms,
            null,
            ExpressionContext::WorkflowSuccessActions,
        );
        yield from $this->walkActions(
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
        yield from $this->walkCriteria(
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
        yield from $this->walkActions($s->flow->onSuccess, "/workflows/{$wi}/steps/{$si}/onSuccess", $syms, $s->stepId, ExpressionContext::OnSuccess);
        yield from $this->walkActions($s->flow->onFailure, "/workflows/{$wi}/steps/{$si}/onFailure", $syms, $s->stepId, ExpressionContext::OnFailure);
        yield from $this->walkActions($s->flow->onTimeout, "/workflows/{$wi}/steps/{$si}/onTimeout", $syms, $s->stepId, ExpressionContext::OnFailure);
        yield from $this->walkActions($s->flow->onCancel, "/workflows/{$wi}/steps/{$si}/onCancel", $syms, $s->stepId, ExpressionContext::OnFailure);
    }

    /**
     * @param  array<Action|Reusable>  $actions
     * @return iterable<ExpressionSite>
     */
    private function walkActions(array $actions, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        foreach ($actions as $ai => $action) {
            if ($action instanceof Reusable) {
                continue;
            }

            $actionPointer = "{$basePointer}/{$ai}";

            if ($action instanceof SuccessAction || $action instanceof FailureAction) {
                yield from $this->walkCriteria($action->criteria, "{$actionPointer}/criteria", $syms, $stepId, $context);
            }

            if (property_exists($action, 'parameters') && is_array($action->parameters)) {
                yield from $this->walkActionParameters($action->parameters, "{$actionPointer}/parameters", $syms, $stepId, $context);
            }
        }
    }

    /**
     * @param  array<mixed>  $parameters
     * @return iterable<ExpressionSite>
     */
    private function walkActionParameters(array $parameters, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        if (array_is_list($parameters)) {
            foreach ($parameters as $pi => $p) {
                if ($p instanceof Reusable) {
                    continue;
                }
                if ($p instanceof Parameter) {
                    yield from $this->extract($p->value, "{$basePointer}/{$pi}/value", $syms, $stepId, $context);
                }
            }
        } else {
            foreach ($parameters as $name => $value) {
                yield from $this->extract($value, "{$basePointer}/{$name}", $syms, $stepId, $context);
            }
        }
    }

    /**
     * @param  list<SuccessCriterion>  $criteria
     * @return iterable<ExpressionSite>
     */
    private function walkCriteria(array $criteria, string $basePointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        foreach ($criteria as $ci => $c) {
            if ($c->context !== null) {
                foreach ($this->extractExpressionTokens($c->context) as $token) {
                    yield new ExpressionSite("{$basePointer}/{$ci}/context", new Expression($token), $syms, $stepId, $context);
                }
            }
            foreach ($this->extractExpressionTokens($c->condition) as $token) {
                yield new ExpressionSite("{$basePointer}/{$ci}/condition", new Expression($token), $syms, $stepId, $context);
            }
        }
    }

    /** @return list<string> */
    private function extractExpressionTokens(string $text): array
    {
        $tokens = [];
        if (preg_match_all(self::RUNTIME_EXPR_PATTERN, $text, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $raw = $match[0];
                if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
                    $inner = trim(!empty($match[1]) ? $match[1] : (!empty($match[2]) ? $match[2] : ''));
                    if ($inner !== '') {
                        $tokens[] = str_starts_with($inner, '$') ? $inner : '$'.$inner;
                    }
                } else {
                    $tokens[] = rtrim($raw, " \t\n\r,;)");
                }
            }
        }

        return $tokens;
    }

    /**
     * @return iterable<ExpressionSite>
     */
    private function extract(mixed $value, string $pointer, ?WorkflowSymbols $syms, ?string $stepId, ExpressionContext $context): iterable
    {
        if ($value instanceof Expression) {
            yield new ExpressionSite($pointer, $value, $syms, $stepId, $context);
        } elseif ($value instanceof Selector) {
            if ($value->context !== null && str_starts_with($value->context, '$')) {
                yield new ExpressionSite("{$pointer}/context", new Expression('{'.$value->context.'}'), $syms, $stepId, $context);
            }
        }
    }
}
