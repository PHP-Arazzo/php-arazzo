<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Dependency\DependencyGraph;
use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Runner\Exceptions\GotoTargetNotFoundException;
use Alama\Arazzo\Runner\Exceptions\WorkflowCycleException;
use Alama\Arazzo\Runner\Exceptions\WorkflowDepthExceededException;

/**
 * Pure graph navigation, action resolution, and workflow-entry checks for
 * {@see WorkflowEngine}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WorkflowNavigator
{
    /**
     * @return list<SuccessAction|FailureAction>
     */
    public function resolveActions(ArazzoDocument $document, Workflow $workflow, Step $step, bool $criteriaMet): array
    {
        $actions = $criteriaMet ? $step->flow->onSuccess : $step->flow->onFailure;
        if ($actions === []) {
            $actions = $criteriaMet ? $workflow->successActions : $workflow->failureActions;
        }
        $type = $criteriaMet ? 'successActions' : 'failureActions';

        return array_map(
            fn (SuccessAction|FailureAction|Reusable $action): SuccessAction|FailureAction => $this->resolveAction($document, $action, $type),
            $actions,
        );
    }

    public function assertTargetExists(ArazzoDocument $document, string $workflowId, string $stepId): void
    {
        $workflow = $this->findWorkflow($document, $workflowId);
        if ($workflow !== null) {
            foreach ($workflow->steps as $step) {
                if ($step->stepId === $stepId) {
                    return;
                }
            }
        }

        throw new GotoTargetNotFoundException("Action references unknown stepId '{$stepId}' in workflow '{$workflowId}'.");
    }

    public function findWorkflow(ArazzoDocument $document, string $workflowId): ?Workflow
    {
        foreach ($document->workflows as $workflow) {
            if ($workflow->workflowId === $workflowId) {
                return $workflow;
            }
        }

        return null;
    }

    public function assertCanEnter(ExecutionState $state, string $workflowId): void
    {
        if (in_array($workflowId, $state->workflowCallStack, true)) {
            throw new WorkflowCycleException("Workflow '{$workflowId}' is already on the execution call stack.");
        }
        if (count($state->workflowCallStack) >= $state->maxWorkflowDepth) {
            throw new WorkflowDepthExceededException("Execution exceeded workflow depth {$state->maxWorkflowDepth}.");
        }
    }

    /**
     * @return list<string>
     */
    public function stepsBetween(Workflow $workflow, ?string $source, ?string $target): array
    {
        if ($source === null || $target === null || $source === $target) {
            return [];
        }

        $order = (new DependencyGraph($workflow->steps))->getTopologicalOrder();
        $sourceIndex = array_search($source, $order, true);
        $targetIndex = array_search($target, $order, true);

        // Only forward jumps (target after source) skip the steps in between.
        if (!is_int($sourceIndex) || !is_int($targetIndex) || $targetIndex <= $sourceIndex) {
            return [];
        }

        return array_values(array_slice($order, $sourceIndex + 1, $targetIndex - $sourceIndex - 1));
    }

    public function nextRunnable(Workflow $workflow, ExecutionState $state, string $completed): ?string
    {
        $graph = new DependencyGraph($workflow->steps);

        foreach ($graph->getTopologicalOrder() as $candidateId) {
            if ($candidateId === $completed || isset($state->stepResults[$candidateId])) {
                continue;
            }
            if (in_array($candidateId, $state->getSkippedSteps(), true)) {
                continue;
            }
            if ($this->dependenciesSatisfied($graph, $candidateId, $state)) {
                return $candidateId;
            }
        }

        return null;
    }

    private function dependenciesSatisfied(DependencyGraph $graph, string $candidateId, ExecutionState $state): bool
    {
        foreach ($graph->getEffectiveDependencies($candidateId) as $dependency) {
            if (!isset($state->stepResults[$dependency])) {
                return false;
            }
        }

        return true;
    }

    private function resolveAction(
        ArazzoDocument $document,
        SuccessAction|FailureAction|Reusable $action,
        string $type,
    ): SuccessAction|FailureAction {
        if (!$action instanceof Reusable) {
            return $action;
        }

        $prefix = "\$components.{$type}.";
        if (!str_starts_with($action->reference, $prefix)) {
            throw new GotoTargetNotFoundException("Reusable reference '{$action->reference}' does not target components.{$type}.");
        }

        $name = substr($action->reference, strlen($prefix));
        $resolved = $type === 'successActions'
            ? ($document->components->successActions[$name] ?? null)
            : ($document->components->failureActions[$name] ?? null);

        if ($resolved === null) {
            throw new GotoTargetNotFoundException("Reusable reference '{$action->reference}' does not resolve.");
        }

        return $resolved;
    }
}
