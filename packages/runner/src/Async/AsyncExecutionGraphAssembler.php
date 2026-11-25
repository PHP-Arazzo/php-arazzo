<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\AsyncConfig;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\SourcePolicyResolver;
use Alama\Arazzo\Runner\Plugins\OperationExecutor\SubWorkflowStepExecutor;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;

/**
 * Assembles the durable/async worker, correlation resumer, and start/resume
 * operations from {@see AsyncConfig}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncExecutionGraphAssembler
{
    public function __construct(
        private readonly DocumentInterface $document,
        private readonly EvaluationEngineInterface $evaluationEngine,
        private readonly AsyncConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function start(
        ArazzoDocument $document,
        string $workflowId,
        array $inputs = [],
        ?string $executionId = null,
        ?string $definitionId = null,
    ): string {
        $workflow = $document->findWorkflow($workflowId) ?? throw UnknownWorkflowException::forId($workflowId);
        (new SourcePolicyResolver())->validate($document, $this->config->options->policy);
        $inputs = $this->runStartPreflight($document, $workflow, $inputs);

        $executionId ??= bin2hex(random_bytes(8));
        $resolvedDefinitionId = $this->resolveDefinitionId($document, $definitionId);

        $this->config->ports->store->markStarted($executionId, $resolvedDefinitionId, $workflowId);

        $context = (new WorkflowContext($resolvedDefinitionId, $inputs))
            ->withExecutionId($executionId)
            ->withWorkflowId($workflowId);

        $firstStep = $workflow->firstStep();
        if ($firstStep !== null) {
            $this->config->ports->queueDriver->dispatch(new ExecuteStepJob($firstStep, $context));
        }

        return $executionId;
    }

    public function resume(ArazzoDocument $document, string $executionId): void
    {
        $persisted = $this->config->ports->store->loadState($executionId);
        if ($persisted === null) {
            throw ExecutionError::stateMissing($executionId);
        }

        $workflowId = is_string($persisted['workflowId'] ?? null) ? $persisted['workflowId'] : '';
        $workflow = $document->findWorkflow($workflowId) ?? throw UnknownWorkflowException::forId($workflowId);

        $definitionId = is_string($persisted['definitionId'] ?? null) ? $persisted['definitionId'] : $workflowId;
        $this->config->ports->store->markStarted($executionId, $definitionId, $workflowId);

        $context = WorkflowContext::fromPersisted($persisted, $executionId);

        foreach ($workflow->steps as $step) {
            if ($context->getStepStatus($step->stepId) !== StepStatus::Succeeded) {
                $this->config->ports->queueDriver->dispatch(new ExecuteStepJob($step, $context));

                return;
            }
        }
    }

    public function buildWorker(): StepExecutionWorker
    {
        $workflowEngine = $this->workflowEngine();
        $preflight = $this->preflight();
        $registry = $this->executorRegistry($workflowEngine, $preflight);

        $controlFlow = new RunControlFlow(
            workflowEngine: $workflowEngine,
            queueDriver: $this->config->ports->queueDriver,
            observability: $this->config->options->observability,
            policy: $this->config->options->policy,
            preflight: $preflight,
        );

        return new StepExecutionWorker(
            $this->config->ports,
            $this->evaluationEngine,
            $registry,
            $controlFlow,
        );
    }

    public function buildResumer(): CorrelationResumer
    {
        $workflowEngine = $this->workflowEngine();
        $preflight = $this->preflight();
        $workflowExecutor = $this->workflowExecutor($workflowEngine, $preflight);

        $controlFlow = new RunControlFlow(
            workflowEngine: $workflowEngine,
            queueDriver: $this->config->ports->queueDriver,
            observability: $this->config->options->observability,
            policy: $this->config->options->policy,
            preflight: $preflight,
        );

        $invoker = new SubWorkflowInvoker(
            $this->config->ports->definitions,
            $workflowExecutor,
            $this->evaluationEngine,
        );

        $outcomeHandler = new StepOutcomeHandler(
            $this->config->ports->store,
            $controlFlow,
            $invoker,
            $this->evaluationEngine,
        );

        return new CorrelationResumer(
            $this->config->ports,
            $this->evaluationEngine,
            $outcomeHandler,
            $this->config->options->observability,
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function runStartPreflight(ArazzoDocument $document, Workflow $workflow, array $inputs): array
    {
        if (!$this->config->options->policy->preflight) {
            return $inputs;
        }

        $errors = [];
        $warnings = [];

        $inputsSchema = $workflow->inputs;
        if (is_array($inputsSchema) && $inputsSchema !== []) {
            $inputResult = $this->document->validateInputs($document, $workflow->workflowId, $inputs);
            if (!$inputResult->isValid()) {
                $errors = [...$errors, ...$inputResult->errors];
                $warnings = [...$warnings, ...$inputResult->warnings];
            } elseif ($inputResult->inputs !== []) {
                $inputs = $inputResult->inputs;
            }
        }

        $preflight = $this->preflight();
        if ($preflight !== null) {
            $preflightResult = $preflight->validate($document);
            $errors = [...$errors, ...$preflightResult->errors];
            $warnings = [...$warnings, ...$preflightResult->warnings];
        }

        if ($errors !== []) {
            throw PreflightFailureException::fromResult(new ValidationResult($document, $errors, $warnings));
        }

        return $inputs;
    }

    private function resolveDefinitionId(ArazzoDocument $document, ?string $definitionId): string
    {
        if ($definitionId !== null && $this->config->ports->definitions->find($definitionId) !== null) {
            return $definitionId;
        }

        return $this->config->ports->definitions->save($document, $definitionId);
    }

    private function workflowEngine(): WorkflowEngine
    {
        return new WorkflowEngine(
            $this->evaluationEngine,
            maxRetryAttempts: $this->config->options->policy->retryPolicy,
        );
    }

    private function preflight(): ?Preflight
    {
        if (!$this->config->options->policy->preflight || $this->config->options->operationResolvers === []) {
            return null;
        }

        return new Preflight(new OperationResolvers($this->config->options->operationResolvers));
    }

    private function executorRegistry(WorkflowEngine $workflowEngine, ?Preflight $preflight): OperationExecutorRegistry
    {
        $graphRegistry = OperationExecutorRegistry::create()->snapshot($this->config->options->executors);

        $workflowExecutor = $this->buildWorkflowExecutor($graphRegistry, $workflowEngine, $preflight);

        $graphRegistry->register(new SubWorkflowStepExecutor($workflowExecutor, $this->evaluationEngine));
        foreach ($this->config->options->executors as $override) {
            $graphRegistry->register($override);
        }

        return $graphRegistry;
    }

    private function workflowExecutor(WorkflowEngine $workflowEngine, ?Preflight $preflight): WorkflowExecutor
    {
        $graphRegistry = OperationExecutorRegistry::create()->snapshot($this->config->options->executors);
        $workflowExecutor = $this->buildWorkflowExecutor($graphRegistry, $workflowEngine, $preflight);

        $graphRegistry->register(new SubWorkflowStepExecutor($workflowExecutor, $this->evaluationEngine));
        foreach ($this->config->options->executors as $override) {
            $graphRegistry->register($override);
        }

        return $workflowExecutor;
    }

    private function buildWorkflowExecutor(
        OperationExecutorRegistry $graphRegistry,
        WorkflowEngine $workflowEngine,
        ?Preflight $preflight,
    ): WorkflowExecutor {
        $stepExecutor = new StepExecutor(
            evaluationEngine: $this->evaluationEngine,
            executors: $graphRegistry,
            outputExtractor: $this->config->ports->outputExtractor,
            policy: $this->config->options->policy,
        );

        return new WorkflowExecutor(
            stepExecutor: $stepExecutor,
            workflowEngine: $workflowEngine,
            events: $this->config->options->observability,
            preflight: $preflight,
            document: $this->config->options->policy->preflight ? $this->document : null,
        );
    }
}
