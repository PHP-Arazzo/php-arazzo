<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Preflight;

use Alama\Arazzo\Contracts\Dependency\ImplicitDependencies;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Runner\Exceptions\AmbiguousStepException;
use Alama\Arazzo\Runner\Exceptions\UnknownStepException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class StepPreflightValidator
{
    /**
     * Resolves the target workflow for runStep, throwing domain exceptions if invalid/ambiguous.
     *
     * @throws UnknownWorkflowException
     * @throws UnknownStepException
     * @throws AmbiguousStepException
     */
    public function resolveTargetWorkflow(ArazzoDocument $document, string $stepId, ?string $workflowId = null): Workflow
    {
        if ($workflowId !== null) {
            return $document->findWorkflow($workflowId) ?? throw UnknownWorkflowException::forId($workflowId);
        }

        $matchingWorkflows = [];
        $allValidIds = [];
        foreach ($document->workflows as $w) {
            foreach ($w->steps as $s) {
                $allValidIds[$s->stepId] = true;
            }
            if ($w->findStep($stepId) !== null) {
                $matchingWorkflows[] = $w;
            }
        }

        if ($matchingWorkflows === []) {
            throw UnknownStepException::forId($stepId, array_keys($allValidIds));
        }
        if (count($matchingWorkflows) > 1) {
            $wfIds = array_map(static fn (Workflow $w): string => $w->workflowId, $matchingWorkflows);
            throw AmbiguousStepException::forId($stepId, $wfIds);
        }

        return $matchingWorkflows[0];
    }

    /**
     * @param  list<Error>  $errors
     */
    public function validateStepTarget(ArazzoDocument $document, string $stepId, ?string &$workflowId, array &$errors): void
    {
        if ($workflowId !== null) {
            $workflow = $document->findWorkflow($workflowId) ?? throw UnknownWorkflowException::forId($workflowId);
            if ($workflow->findStep($stepId) === null) {
                $validIds = $workflow->steps->stepIds();
                $errors[] = new Error(
                    'step.unknown',
                    "Unknown step '{$stepId}' in workflow '{$workflowId}'.".($validIds !== [] ? ' Valid step IDs: '.implode(', ', $validIds).'.' : ''),
                    "/workflows/{$workflowId}/steps",
                );
            }

            return;
        }

        $matchingWorkflows = [];
        $allValidIds = [];
        foreach ($document->workflows as $w) {
            foreach ($w->steps as $s) {
                $allValidIds[$s->stepId] = true;
            }
            if ($w->findStep($stepId) !== null) {
                $matchingWorkflows[] = $w;
            }
        }

        if ($matchingWorkflows === []) {
            $validList = array_keys($allValidIds);
            $errors[] = new Error(
                'step.unknown',
                "Unknown step '{$stepId}'.".($validList !== [] ? ' Valid step IDs: '.implode(', ', $validList).'.' : ''),
                '/workflows',
            );
        } elseif (count($matchingWorkflows) > 1) {
            $wfIds = array_map(static fn (Workflow $w): string => $w->workflowId, $matchingWorkflows);
            $errors[] = new Error(
                'step.ambiguous',
                "Step '{$stepId}' is ambiguous; it exists in multiple workflows: ".implode(', ', $wfIds).'. Please specify a workflowId.',
                '/workflows',
            );
        } else {
            $workflowId = $matchingWorkflows[0]->workflowId;
        }
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  list<Error>  $errors
     */
    public function validateStepDependencies(ArazzoDocument $document, string $stepId, string $workflowId, array $inputs, array &$errors): void
    {
        $workflow = $document->findWorkflow($workflowId);
        $step = $workflow?->findStep($stepId);
        if ($step === null) {
            return;
        }

        $refs = ImplicitDependencies::outputReferencesFromStep($step);
        foreach ($refs as $ref) {
            $depStepId = $ref['stepId'];
            $outputKey = $ref['outputKey'];
            if (!$this->hasDependencyValue($inputs, $depStepId, $outputKey)) {
                $msg = $outputKey !== null
                    ? "Step '{$stepId}' requires output '{$outputKey}' from upstream step '{$depStepId}', but it is missing."
                    : "Step '{$stepId}' requires output from upstream step '{$depStepId}', but it is missing.";
                $errors[] = new Error(
                    'step.missing_dependency_output',
                    $msg,
                    "/workflows/{$workflowId}/steps/{$stepId}",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function hasDependencyValue(array $inputs, string $depStepId, ?string $outputKey): bool
    {
        if ($outputKey !== null) {
            return array_key_exists($outputKey, $inputs)
                || array_key_exists("steps.{$depStepId}.outputs.{$outputKey}", $inputs)
                || array_key_exists("{$depStepId}.{$outputKey}", $inputs)
                || (isset($inputs[$depStepId]) && is_array($inputs[$depStepId]) && isset($inputs[$depStepId][$outputKey]));
        }

        return isset($inputs[$depStepId]) || isset($inputs["steps.{$depStepId}.outputs"]);
    }
}
