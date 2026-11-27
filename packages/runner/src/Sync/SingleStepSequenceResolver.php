<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Dependency\DependencyGraph;
use Alama\Arazzo\Contracts\Dependency\ImplicitDependencies;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SingleStepSequenceResolver
{
    /**
     * @param  array<string, mixed>  $inputs
     * @return array{0: WorkflowContext, 1: list<string>}
     */
    public function resolve(
        Workflow $workflow,
        Step $targetStep,
        array $inputs,
        WorkflowContext $context,
        ?ExecutionPolicy $policy = null,
    ): array {
        $stepId = $targetStep->stepId;
        if ($policy !== null && $policy->noDeps) {
            $context = $this->prepareNoDepsContext($targetStep, $inputs, $context);

            return [$context, [$stepId]];
        }

        $graph = new DependencyGraph($workflow->steps->all());
        $deps = $graph->getTransitiveDependencies($stepId);

        return [$context, [...$deps, $stepId]];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function prepareNoDepsContext(Step $targetStep, array $inputs, WorkflowContext $context): WorkflowContext
    {
        $refs = ImplicitDependencies::outputReferencesFromStep($targetStep);

        foreach ($refs as $ref) {
            $depStepId = $ref['stepId'];
            $outputKey = $ref['outputKey'];

            if ($outputKey !== null) {
                $context = $this->seedSingleOutput($targetStep, $inputs, $context, $depStepId, $outputKey);

                continue;
            }

            $context = $this->seedAllOutputs($targetStep, $inputs, $context, $depStepId);
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function seedSingleOutput(Step $targetStep, array $inputs, WorkflowContext $context, string $depStepId, string $outputKey): WorkflowContext
    {
        if ($context->getStepOutput($depStepId, $outputKey) !== null) {
            return $context;
        }

        $val = $this->resolveOutputValue($inputs, $depStepId, $outputKey);
        if ($val !== null) {
            return $context->withStepOutput($depStepId, $outputKey, $val);
        }

        throw ExecutionError::missingDependencyOutput($targetStep->stepId, $outputKey, $depStepId);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function seedAllOutputs(Step $targetStep, array $inputs, WorkflowContext $context, string $depStepId): WorkflowContext
    {
        if ($context->getStepOutputs($depStepId) !== []) {
            return $context;
        }

        $map = $this->resolveOutputsMap($inputs, $depStepId);
        if ($map !== null && $map !== []) {
            foreach ($map as $k => $v) {
                $context = $context->withStepOutput($depStepId, (string) $k, $v);
            }

            return $context;
        }

        throw ExecutionError::missingDependencyOutput($targetStep->stepId, 'outputs', $depStepId);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function resolveOutputValue(array $inputs, string $depStepId, string $outputKey): mixed
    {
        return $inputs[$outputKey]
            ?? $inputs["steps.{$depStepId}.outputs.{$outputKey}"]
            ?? $inputs["{$depStepId}.{$outputKey}"]
            ?? (is_array($inputs[$depStepId] ?? null) ? ($inputs[$depStepId][$outputKey] ?? null) : null)
            ?? (
                is_array($inputs['steps'] ?? null)
                && is_array($inputs['steps'][$depStepId] ?? null)
                && is_array($inputs['steps'][$depStepId]['outputs'] ?? null)
                    ? ($inputs['steps'][$depStepId]['outputs'][$outputKey] ?? null)
                    : null
            );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>|null
     */
    private function resolveOutputsMap(array $inputs, string $depStepId): ?array
    {
        $stepsVal = is_array($inputs['steps'] ?? null) ? ($inputs['steps'][$depStepId] ?? null) : null;
        $stepsOutputs = is_array($stepsVal) ? ($stepsVal['outputs'] ?? null) : null;

        $map = ($inputs[$depStepId] ?? null)
            ?? $stepsOutputs
            ?? ($inputs["steps.{$depStepId}.outputs"] ?? null);

        if (!is_array($map)) {
            return null;
        }

        /** @var array<string, mixed> $map */
        return $map;
    }
}
