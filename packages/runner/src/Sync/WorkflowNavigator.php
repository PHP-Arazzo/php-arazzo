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
        $type = $criteriaMet ? 'successActions' : 'failureActions';

        return array_map(
            fn (SuccessAction|FailureAction|Reusable $action): SuccessAction|FailureAction => $this->resolveAction($document, $action, $type),
            $step->effectiveActions($criteriaMet, $workflow),
        );
    }

    public function assertTargetExists(ArazzoDocument $document, string $workflowId, string $stepId): void
    {
        $workflow = $document->findWorkflow($workflowId);
        if ($workflow !== null && $workflow->findStep($stepId) !== null) {
            return;
        }

        throw GotoTargetNotFoundException::unknownStep($stepId, $workflowId);
    }

    public function assertCanEnter(ExecutionState $state, string $workflowId): void
    {
        if (in_array($workflowId, $state->workflowCallStack, true)) {
            throw WorkflowCycleException::detected($workflowId);
        }
        if (count($state->workflowCallStack) >= $state->maxWorkflowDepth) {
            throw WorkflowDepthExceededException::exceeded($state->maxWorkflowDepth);
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
            throw GotoTargetNotFoundException::wrongReusableTarget($action->reference, $type);
        }

        $name = substr($action->reference, strlen($prefix));
        $resolved = $type === 'successActions'
            ? $document->components->findSuccessAction($name)
            : $document->components->findFailureAction($name);

        if ($resolved === null) {
            throw GotoTargetNotFoundException::unresolvableReusable($action->reference);
        }

        return $resolved;
    }
}
