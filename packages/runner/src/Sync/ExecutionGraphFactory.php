<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Execution\Data\RunnerOptions;
use Alama\Arazzo\Runner\Plugins\OperationExecutor\SubWorkflowStepExecutor;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;

/**
 * Assembles the synchronous runner execution graph.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ExecutionGraphFactory
{
    public function __construct(
        private readonly DocumentInterface $document,
        private readonly EvaluationEngineInterface $evaluationEngine,
        private readonly RunnerOptions $options = new RunnerOptions(),
    ) {}

    public function createWorkflowExecutor(?OperationExecutorRegistry $executors = null): WorkflowExecutor
    {
        // Snapshot the registry so registering this graph's SubWorkflowStepExecutor
        // and per-instance overrides never mutates the global singleton.
        $base = $executors ?? OperationExecutorRegistry::create();
        $graphRegistry = $base->snapshot($this->options->executors);

        $stepExecutor = new StepExecutor(
            evaluationEngine: $this->evaluationEngine,
            executors: $graphRegistry,
            policy: $this->options->policy,
        );

        $preflight = $this->options->policy->preflight && $this->options->operationResolvers !== []
            ? new Preflight(new OperationResolvers($this->options->operationResolvers))
            : null;

        $workflowEngine = new WorkflowEngine(
            $this->evaluationEngine,
            maxRetryAttempts: $this->options->policy->retryPolicy,
        );

        $workflowExecutor = new WorkflowExecutor(
            stepExecutor: $stepExecutor,
            workflowEngine: $workflowEngine,
            events: $this->options->observability,
            preflight: $preflight,
            document: $this->options->policy->preflight ? $this->document : null,
        );

        // Register SubWorkflowStepExecutor unless the caller explicitly overrode
        // the SubWorkflow protocol on this Runner instance.
        $graphRegistry->register(new SubWorkflowStepExecutor($workflowExecutor, $this->evaluationEngine));
        foreach ($this->options->executors as $override) {
            $graphRegistry->register($override);
        }

        return $workflowExecutor;
    }
}
