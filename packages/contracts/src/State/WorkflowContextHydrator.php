<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\State;

/**
 * Hydrates and reconciles {@see WorkflowContext} instances from persisted payloads.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WorkflowContextHydrator
{
    /**
     * @param  array<string, mixed>  $persisted
     */
    public static function fromPersisted(array $persisted, string $executionId): WorkflowContext
    {
        /** @var array<string, mixed> $inputs */
        $inputs = self::arrayField($persisted, 'inputs');
        /** @var array<string, array<string, mixed>> $steps */
        $steps = self::arrayField($persisted, 'steps');
        /** @var array<string, mixed> $components */
        $components = self::arrayField($persisted, 'components');
        /** @var list<string> $skippedSteps */
        $skippedSteps = self::arrayField($persisted, 'skippedSteps');

        $context = new WorkflowContext(
            self::stringField($persisted, 'definitionId'),
            $inputs,
            $steps,
            $components,
            self::stringField($persisted, 'workflowId'),
            $executionId,
            workflows: WorkflowDataMap::from($persisted['workflows'] ?? null),
            skippedSteps: $skippedSteps,
        );

        return self::applyPersistedBudget($context, $persisted);
    }

    /**
     * @param  array<string, mixed>  $persisted
     */
    public static function reconciled(WorkflowContext $incoming, array $persisted, string $executionId): WorkflowContext
    {
        /** @var array<string, array<string, mixed>> $persistedSteps */
        $persistedSteps = self::arrayField($persisted, 'steps');
        /** @var array<string, array<string, mixed>> $mergedSteps */
        $mergedSteps = array_merge($incoming->getSteps(), $persistedSteps);
        $persistedWorkflows = WorkflowDataMap::from($persisted['workflows'] ?? null);

        /** @var list<string> $skippedSteps */
        $skippedSteps = $persisted['skippedSteps'] ?? $incoming->getSkippedSteps();

        $restored = new WorkflowContext(
            $incoming->getDefinitionId(),
            $incoming->getInputs(),
            $mergedSteps,
            $incoming->getComponents(),
            $incoming->getWorkflowId(),
            $executionId,
            workflows: $incoming->getWorkflows(),
            skippedSteps: $skippedSteps,
        );

        $restored = self::applyPersistedBudget($restored, $persisted);

        // Persisted workflow outputs are newer than anything the incoming
        // job carried, so they win per workflow without dropping untouched ones.
        foreach ($persistedWorkflows as $workflowId => $data) {
            $restored = $restored->withWorkflowData($workflowId, $data);
        }

        return $restored;
    }

    /**
     * @param  array<string, mixed>  $persisted
     */
    private static function applyPersistedBudget(WorkflowContext $context, array $persisted): WorkflowContext
    {
        if (!array_key_exists('stepsSpent', $persisted) && !array_key_exists('workflowCallStack', $persisted)) {
            return $context;
        }

        $stack = self::arrayField($persisted, 'workflowCallStack');
        $stepsSpent = is_int($persisted['stepsSpent'] ?? null) ? $persisted['stepsSpent'] : 0;

        return $context->withBudget(
            $stepsSpent,
            array_values(array_filter($stack, 'is_string')),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function stringField(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<mixed>
     */
    private static function arrayField(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
