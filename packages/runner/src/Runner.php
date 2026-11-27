<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Contracts\Validation\Warning;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\RunnerOptions;
use Alama\Arazzo\Runner\Execution\SourcePolicyResolver;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Preflight\StepPreflightValidator;
use Alama\Arazzo\Runner\Sync\ExecutionGraphFactory;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Public façade for synchronous, protocol-agnostic Arazzo workflow execution.
 */
final readonly class Runner implements RunnerInterface
{
    private DocumentInterface $document;

    private EvaluationEngineInterface $evaluationEngine;

    private RunnerOptions $options;

    public function __construct(
        ?DocumentInterface $document = null,
        ?EvaluationEngineInterface $evaluationEngine = null,
        ?RunnerOptions $options = null,
    ) {
        $this->document = $document ?? Document::create();
        $this->evaluationEngine = $evaluationEngine ?? new EvaluationEngine(expression: ExpressionEngine::create());
        $this->options = $options ?? new RunnerOptions();
    }

    public static function create(): self
    {
        return new self();
    }

    public function run(
        ArazzoDocument $document,
        string $workflowId,
        array $inputs = [],
        ?ExecutionPolicy $policy = null,
    ): WorkflowExecutionResult {
        $workflow = $document->findWorkflow($workflowId) ?? throw UnknownWorkflowException::forId($workflowId);
        $effectiveOptions = $policy !== null ? $this->options->withPolicy($policy) : $this->options;

        (new SourcePolicyResolver())->validate($document, $effectiveOptions->policy);

        $executor = (new ExecutionGraphFactory($this->document, $this->evaluationEngine, $effectiveOptions))
            ->createWorkflowExecutor();

        return $executor->execute($workflow, $document, $inputs);
    }

    public function runStep(
        ArazzoDocument $document,
        string $stepId,
        array $inputs = [],
        ?string $workflowId = null,
        ?ExecutionPolicy $policy = null,
    ): WorkflowExecutionResult {
        $workflow = (new StepPreflightValidator())->resolveTargetWorkflow($document, $stepId, $workflowId);

        $effectiveOptions = $policy !== null ? $this->options->withPolicy($policy) : $this->options;

        (new SourcePolicyResolver())->validate($document, $effectiveOptions->policy);

        $executor = (new ExecutionGraphFactory($this->document, $this->evaluationEngine, $effectiveOptions))
            ->createWorkflowExecutor();

        return $executor->executeStep($workflow, $document, $stepId, $inputs, policy: $effectiveOptions->policy);
    }

    public function preflight(
        ArazzoDocument $document,
        ?string $workflowId = null,
        array $inputs = [],
        ?string $stepId = null,
    ): ValidationResult {
        $errors = [];
        /** @var list<Warning> $warnings */
        $warnings = [];

        if ($stepId !== null) {
            $stepValidator = new StepPreflightValidator();
            $stepValidator->validateStepTarget($document, $stepId, $workflowId, $errors);
            if ($workflowId !== null && $this->options->policy->noDeps) {
                $stepValidator->validateStepDependencies($document, $stepId, $workflowId, $inputs, $errors);
            }
        }

        if ($workflowId !== null) {
            $inputs = $this->validateWorkflowInputs($document, $workflowId, $inputs, $errors, $warnings);
        }

        if ($this->options->operationResolvers !== []) {
            $this->validateResolvers($document, $errors, $warnings);
        }

        return new ValidationResult($document, $errors, $warnings, $inputs);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  list<Error>  $errors
     * @param  list<Warning>  $warnings
     * @return array<string, mixed>
     */
    private function validateWorkflowInputs(ArazzoDocument $document, string $workflowId, array $inputs, array &$errors, array &$warnings): array
    {
        $workflow = $document->findWorkflow($workflowId);
        if ($workflow === null) {
            return $inputs;
        }

        $inputsSchema = $workflow->inputs;
        if (!is_array($inputsSchema) || $inputsSchema === []) {
            return $inputs;
        }

        $inputResult = $this->document->validateInputs($document, $workflow->workflowId, $inputs);
        $errors = [...$errors, ...$inputResult->errors];
        $warnings = [...$warnings, ...$inputResult->warnings];

        return $inputResult->isValid() && $inputResult->inputs !== [] ? $inputResult->inputs : $inputs;
    }

    /**
     * @param  list<Error>  $errors
     * @param  list<Warning>  $warnings
     */
    private function validateResolvers(ArazzoDocument $document, array &$errors, array &$warnings): void
    {
        $preflight = new Preflight(new OperationResolvers($this->options->operationResolvers));
        $stepResult = $preflight->validate($document);
        $errors = [...$errors, ...$stepResult->errors];
        $warnings = [...$warnings, ...$stepResult->warnings];
    }

    public function withPolicy(ExecutionPolicy $policy): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withPolicy($policy));
    }

    public function withExecutor(OperationExecutorPluginInterface $executor): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withExecutor($executor));
    }

    public function withOperationResolver(OperationResolverInterface $resolver): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withOperationResolver($resolver));
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withEventDispatcher($dispatcher));
    }

    public function withLogger(LoggerInterface $logger): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withLogger($logger));
    }

    public function withTracer(TracerInterface $tracer): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withTracer($tracer));
    }

    public function withClock(ClockInterface $clock): static
    {
        return new self($this->document, $this->evaluationEngine, $this->options->withClock($clock));
    }
}
