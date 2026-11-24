<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Renderer;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;

/**
 * Renders an Arazzo document as Markdown reference documentation.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class MarkdownRenderer
{
    public function render(ArazzoDocument $document): string
    {
        $out = '# '.$document->info->title."\n\n";

        if (($document->info->description ?? null) !== null) {
            $out .= $document->info->description."\n\n";
        }

        $out .= "- arazzo: **{$document->arazzo}**\n";
        $out .= '- version: **'.$document->info->version."**\n\n";

        if ($document->sourceDescriptions !== []) {
            $out .= $this->renderSources($document->sourceDescriptions);
        }

        foreach ($document->workflows as $workflow) {
            $out .= $this->renderWorkflow($workflow);
        }

        return $out;
    }

    /**
     * @param  list<SourceDescription>  $sources
     */
    private function renderSources(array $sources): string
    {
        $out = "## Sources\n\n| Name | Type | URL |\n|---|---|---|\n";

        foreach ($sources as $source) {
            $out .= sprintf("| %s | %s | %s |\n", $source->name, $source->type->value, $source->url);
        }

        return $out."\n";
    }

    private function renderWorkflow(Workflow $workflow): string
    {
        $out = "## Workflow `{$workflow->workflowId}`\n\n";

        if (($workflow->summary ?? null) !== null) {
            $out .= "_{$workflow->summary}_\n\n";
        }

        if (($workflow->description ?? null) !== null) {
            $out .= $workflow->description."\n\n";
        }

        if ($workflow->steps === []) {
            return $out."_no steps_\n\n";
        }

        $out .= "| # | Step | Target | Criteria | Outputs |\n|---:|---|---|---|---|\n";
        foreach ($workflow->steps as $index => $step) {
            $out .= $this->renderStepRow($index + 1, $step);
        }

        if ($workflow->outputs !== []) {
            $out .= $this->renderWorkflowOutputs($workflow->outputs);
        }

        return $out."\n";
    }

    private function renderStepRow(int $position, Step $step): string
    {
        $criteria = [];
        foreach ($step->io->successCriteria as $criterion) {
            $criteria[] = '`'.$criterion->condition.'`';
        }

        $outputs = [];
        foreach ($step->io->outputs as $name => $expression) {
            $outputs[] = "`{$name}` = `{$this->formatExpression($expression)}`";
        }

        return sprintf(
            "| %d | `%s` | %s | %s | %s |\n",
            $position,
            $step->stepId,
            $this->markdownTarget($step),
            implode('<br/>', $criteria),
            implode('<br/>', $outputs),
        );
    }

    /**
     * @param  array<string, mixed>  $outputs
     */
    private function renderWorkflowOutputs(array $outputs): string
    {
        $out = "\n**Workflow outputs:**\n\n";

        foreach ($outputs as $name => $expression) {
            $out .= "- `{$name}` = `{$this->formatExpression($expression)}`\n";
        }

        return $out;
    }

    private function formatExpression(mixed $expression): string
    {
        if (is_object($expression) && property_exists($expression, 'raw')) {
            return is_scalar($expression->raw) ? (string) $expression->raw : '';
        }

        return (string) json_encode($expression);
    }

    private function markdownTarget(Step $step): string
    {
        if ($step->target->workflowId !== null) {
            return "`→ {$step->target->workflowId}`";
        }

        if (($op = $step->target->operationPath ?? $step->target->operationId) !== null) {
            return "`{$op}`";
        }

        return $step->target->action !== null ? "`[{$step->target->action}]`" : '—';
    }
}
