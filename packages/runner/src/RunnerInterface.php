<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\Exceptions\InvalidSourcePolicyException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\RunnerException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Public entry point for synchronous, protocol-agnostic Arazzo workflow execution.
 */
interface RunnerInterface
{
    /**
     * Execute a workflow by id against an Arazzo document.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws UnknownWorkflowException
     * @throws InvalidSourcePolicyException
     * @throws PreflightFailureException
     * @throws SchemaValidationException
     * @throws RunnerException
     */
    public function run(
        ArazzoDocument $document,
        string $workflowId,
        array $inputs = [],
        ?ExecutionPolicy $policy = null,
    ): ExecutionResult;

    /**
     * Run execution-readiness preflight validation (workflow input schema and
     * registered operation resolvers) without executing any steps.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws UnknownWorkflowException
     */
    public function preflight(ArazzoDocument $document, ?string $workflowId = null, array $inputs = []): ValidationResult;

    /**
     * Immutably configure the default execution policy (budgets, retry, preflight, source policies).
     */
    public function withPolicy(ExecutionPolicy $policy): static;

    /**
     * Immutably register a per-instance operation executor plugin (overrides global registry).
     */
    public function withExecutor(OperationExecutorPluginInterface $executor): static;

    /**
     * Immutably register an operation resolver used during preflight validation.
     */
    public function withOperationResolver(OperationResolverInterface $resolver): static;

    /**
     * Immutably attach a PSR-14 event dispatcher for workflow lifecycle events.
     */
    public function withEventDispatcher(EventDispatcherInterface $dispatcher): static;

    /**
     * Immutably attach a PSR-3 logger for structured execution diagnostics.
     */
    public function withLogger(LoggerInterface $logger): static;

    /**
     * Immutably attach a vendor-agnostic tracer for workflow and step spans.
     */
    public function withTracer(TracerInterface $tracer): static;

    /**
     * Immutably attach a PSR-20 clock for deterministic timestamps.
     */
    public function withClock(ClockInterface $clock): static;
}
