<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Renderer;

use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;

/**
 * Renders Arazzo documents as Mermaid flowcharts and Markdown docs.
 * Pure presentation: no parsing, no execution.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class Renderer
{
    public function toMermaid(ArazzoDocument $document, ?string $workflowId = null): string
    {
        $lines = ['flowchart TD'];
        $seq = 0;

        foreach ($this->workflows($document, $workflowId) as $workflow) {
            // First pass: assign a node id per step so goto/retry edges can
            // reference steps that have not been emitted yet.
            $nodes = [];

            foreach ($workflow->steps as $step) {
                $nodes[$step->stepId] = 'n'.(++$seq);
            }

            $lines[] = '';
            $lines[] = sprintf('  subgraph %s["workflow: %s"]', $this->id($workflow->workflowId), $this->esc($workflow->workflowId));

            $previous = null;

            foreach ($workflow->steps as $step) {
                $node = $nodes[$step->stepId];
                $label = $step->target->operationId ?? $step->target->operationPath ?? ($step->target->workflowId !== null ? '→ '.$step->target->workflowId : '?');

                $lines[] = sprintf('    %s["%s<br/>%s"]', $node, $this->esc($step->stepId), $this->esc((string) $label));

                if ($previous !== null) {
                    $lines[] = sprintf('    %s --> %s', $previous, $node);
                }

                foreach ($this->edges($step, $nodes) as [$style, $target]) {
                    $lines[] = sprintf('    %s %s %s', $node, $style, $target);
                }

                if ($this->endsSuccessfully($step)) {
                    $lines[] = sprintf('    %s --> done%s(["✔ end"])', $node, ++$seq);
                    $previous = null;

                    continue;
                }

                $previous = $node;
            }

            if ($previous !== null) {
                $lines[] = sprintf('    %s --> done%s(["✔ end"])', $previous, ++$seq);
            }

            $lines[] = '  end';
        }

        return implode("\n", $lines)."\n";
    }

    public function toMarkdown(ArazzoDocument $document): string
    {
        return (new MarkdownRenderer())->render($document);
    }

    /**
     * Mermaid edges for a step's success/failure routing.
     *
     * @return list<array{0:string,1:string}>
     */
    /**
     * @param  array<string,string>  $nodes
     * @return list<array{0:string,1:string}>
     */
    private function edges(Step $step, array $nodes): array
    {
        $edges = [];

        $push = function (bool $failure, string $label, string $targetStepId) use (&$edges, $nodes): void {
            $arrow = $failure ? '-.->|' : '-->|';
            $text = ($failure ? '✘ ' : '✔ ').$label;

            // Unknown targets (e.g. goto into another workflow) get a stub
            // node so the chart stays valid Mermaid.
            $edges[] = [$arrow.$text.'|', $nodes[$targetStepId] ?? ('id_'.$this->id($targetStepId))];
        };

        foreach ([$step->flow->onSuccess, $step->flow->onFailure] as $actions) {
            foreach ($actions as $action) {
                if ($action instanceof Reusable) {
                    continue;
                }

                if ($action instanceof SuccessGotoAction || $action instanceof FailureGotoAction) {
                    $push($action instanceof FailureGotoAction, 'goto', $action->stepId ?? $action->workflowId ?? '?');

                    continue;
                }

                if ($action instanceof RetryAction) {
                    $push(true, 'retry', $step->stepId);
                }
            }
        }

        return $edges;
    }

    private function endsSuccessfully(Step $step): bool
    {
        foreach ($step->flow->onSuccess as $action) {
            if ($action instanceof SuccessEndAction) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Workflow> */
    private function workflows(ArazzoDocument $document, ?string $workflowId): array
    {
        if ($workflowId === null) {
            return $document->workflows;
        }

        foreach ($document->workflows as $workflow) {
            if ($workflow->workflowId === $workflowId) {
                return [$workflow];
            }
        }

        return [];
    }

    private function esc(string $value): string
    {
        return str_replace(['"', "\n"], ["'", ' '], $value);
    }

    private function id(string $raw): string
    {
        return 'id_'.preg_replace('/[^A-Za-z0-9_]/', '_', $raw);
    }
}
