<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;

/**
 * Pure document and context lookup helpers for {@see StepOutcomeHandler}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncContextLookup
{
    public function findAction(ArazzoDocument $document, Workflow $workflow, Step $step, string $class): ?object
    {
        foreach ([$step->flow->onSuccess, $step->flow->onFailure, $workflow->successActions, $workflow->failureActions] as $list) {
            foreach ($list as $action) {
                $resolved = $this->resolveAction($document, $action);
                if ($resolved instanceof $class) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    public function findWorkflow(ArazzoDocument $document, string $workflowId): ?Workflow
    {
        return array_find($document->workflows, fn ($workflow) => $workflow->workflowId === $workflowId);
    }

    public function findStep(Workflow $workflow, string $stepId): ?Step
    {
        return array_find($workflow->steps, fn ($step) => $step->stepId === $stepId);
    }

    /**
     * @return array<string, mixed>
     */
    public function stepOutputs(WorkflowContext $context, string $stepId): array
    {
        $record = $context->getSteps()[$stepId] ?? null;
        $outputs = is_array($record) ? ($record['outputs'] ?? null) : null;

        if (!is_array($outputs)) {
            return [];
        }

        return array_filter($outputs, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    private function resolveAction(ArazzoDocument $document, object $action): ?object
    {
        if (!$action instanceof Reusable) {
            return $action;
        }

        $name = substr($action->reference, (int) strrpos($action->reference, '.') + 1);

        return str_contains($action->reference, 'failureActions')
            ? ($document->components->failureActions[$name] ?? null)
            : ($document->components->successActions[$name] ?? null);
    }
}
