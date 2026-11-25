<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Async\AsyncExecutionGraphAssembler;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Execution\Data\AsyncConfig;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Public façade for durable, queue-driven Arazzo workflow execution.
 *
 * Ships in-memory defaults on {@see self::create()} for local runs and tests;
 * production hosts bind durable storage, queue, lock, and observability ports
 * via the immutable `with*()` builders.
 */
final readonly class AsyncRunner implements AsyncRunnerInterface
{
    private DocumentInterface $document;

    private EvaluationEngineInterface $evaluationEngine;

    private AsyncConfig $config;

    public function __construct(
        ?DocumentInterface $document = null,
        ?EvaluationEngineInterface $evaluationEngine = null,
        ?AsyncConfig $config = null,
    ) {
        $this->document = $document ?? Document::create();
        $this->evaluationEngine = $evaluationEngine ?? new EvaluationEngine(expression: ExpressionEngine::create());
        $this->config = $config ?? AsyncConfig::defaults();
    }

    public static function create(): self
    {
        return new self();
    }

    public function start(
        ArazzoDocument $document,
        string $workflowId,
        array $inputs = [],
        ?string $executionId = null,
        ?string $definitionId = null,
    ): string {
        return (new AsyncExecutionGraphAssembler($this->document, $this->evaluationEngine, $this->config))
            ->start($document, $workflowId, $inputs, $executionId, $definitionId);
    }

    public function resume(ArazzoDocument $document, string $executionId): void
    {
        (new AsyncExecutionGraphAssembler($this->document, $this->evaluationEngine, $this->config))
            ->resume($document, $executionId);
    }

    public function handleStep(ExecuteStepJob $job): void
    {
        (new AsyncExecutionGraphAssembler($this->document, $this->evaluationEngine, $this->config))
            ->buildWorker()
            ->handle($job);
    }

    public function resumeCorrelation(string $correlationId, array $response): void
    {
        (new AsyncExecutionGraphAssembler($this->document, $this->evaluationEngine, $this->config))
            ->buildResumer()
            ->resume($correlationId, $response);
    }

    public function withStore(ExecutionStoreInterface $store): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withPorts($this->config->ports->withStore($store)));
    }

    public function withDefinitions(DefinitionStoreInterface $definitions): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withPorts($this->config->ports->withDefinitions($definitions)));
    }

    public function withQueueDriver(QueueDriverInterface $queueDriver): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withPorts($this->config->ports->withQueueDriver($queueDriver)));
    }

    public function withLockManager(LockManagerInterface $lockManager): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withPorts($this->config->ports->withLockManager($lockManager)));
    }

    public function withPolicy(ExecutionPolicy $policy): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withPolicy($policy)));
    }

    public function withExecutor(OperationExecutorPluginInterface $executor): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withExecutor($executor)));
    }

    public function withOperationResolver(OperationResolverInterface $resolver): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withOperationResolver($resolver)));
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withEventDispatcher($dispatcher)));
    }

    public function withLogger(LoggerInterface $logger): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withLogger($logger)));
    }

    public function withTracer(TracerInterface $tracer): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withTracer($tracer)));
    }

    public function withClock(ClockInterface $clock): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withOptions($this->config->options->withClock($clock)));
    }

    public function withOutputExtractor(OutputExtractorInterface $outputExtractor): static
    {
        return new self($this->document, $this->evaluationEngine, $this->config->withPorts($this->config->ports->withOutputExtractor($outputExtractor)));
    }
}
