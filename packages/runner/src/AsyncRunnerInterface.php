<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Exceptions\InvalidSourcePolicyException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\RunnerException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Public entry point for durable, queue-driven Arazzo workflow execution.
 */
interface AsyncRunnerInterface
{
    /**
     * Start a durable workflow execution by enqueuing its first runnable step.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws UnknownWorkflowException
     * @throws InvalidSourcePolicyException
     * @throws PreflightFailureException
     * @throws RunnerException
     */
    public function start(
        ArazzoDocument $document,
        string $workflowId,
        array $inputs = [],
        ?string $executionId = null,
        ?string $definitionId = null,
    ): string;

    /**
     * Resume a persisted execution by enqueuing its next incomplete step.
     *
     * @throws UnknownWorkflowException
     * @throws RunnerException
     */
    public function resume(ArazzoDocument $document, string $executionId): void;

    /**
     * Process a single queued step job.
     */
    public function handleStep(ExecuteStepJob $job): void;

    /**
     * Resume a suspended correlation step when its external message arrives.
     *
     * @param  array<string, mixed>  $response
     */
    public function resumeCorrelation(string $correlationId, array $response): void;

    public function withStore(ExecutionStoreInterface $store): static;

    public function withDefinitions(DefinitionStoreInterface $definitions): static;

    public function withQueueDriver(QueueDriverInterface $queueDriver): static;

    public function withLockManager(LockManagerInterface $lockManager): static;

    public function withPolicy(ExecutionPolicy $policy): static;

    public function withExecutor(OperationExecutorPluginInterface $executor): static;

    public function withOperationResolver(OperationResolverInterface $resolver): static;

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): static;

    public function withLogger(LoggerInterface $logger): static;

    public function withTracer(TracerInterface $tracer): static;

    public function withClock(ClockInterface $clock): static;

    public function withOutputExtractor(OutputExtractorInterface $outputExtractor): static;
}
