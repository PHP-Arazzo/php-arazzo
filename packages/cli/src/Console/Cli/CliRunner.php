<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Cli;

use Alama\Arazzo\Cli\Exceptions\CliExecutionException;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\AsyncRunnerInterface;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runtime\Telemetry\OtelTracer;

/**
 * Single-process, resumable workflow runner for CLI usage.
 *
 * Drives {@see AsyncRunnerInterface} with an in-process {@see SyncQueueDriver}
 * so CLI results execute through the canonical durable path without requiring
 * an external queue broker or database.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class CliRunner
{
    private const int MAX_QUEUED_STEPS = 10_000;

    private readonly SyncQueueDriver $queue;

    private readonly AsyncRunnerInterface $runner;

    private readonly TracerInterface $tracer;

    /**
     * @param  list<OperationExecutorPluginInterface>  $protocolExecutors
     */
    public function __construct(
        EvaluationEngineInterface $evaluationEngine,
        private readonly ExecutionStoreInterface $store,
        DefinitionStoreInterface $definitions,
        array $protocolExecutors = [],
        ?TracerInterface $tracer = null,
    ) {
        $this->queue = new SyncQueueDriver();
        $this->tracer = $tracer ?? new OtelTracer();

        $configured = (new AsyncRunner(evaluationEngine: $evaluationEngine))
            ->withStore($this->store)
            ->withDefinitions($definitions)
            ->withQueueDriver($this->queue)
            ->withTracer($this->tracer);

        foreach ($protocolExecutors as $plugin) {
            $configured = $configured->withExecutor($plugin);
        }

        $this->runner = $configured;
    }

    /**
     * Runs a workflow to completion (or suspension) in this process.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function run(ArazzoDocument $document, string $workflowId, array $inputs = [], ?string $executionId = null, ?string $definitionId = null): CliRunResult
    {
        $executionId ??= 'cli_'.bin2hex(random_bytes(8));

        $this->runner->start($document, $workflowId, $inputs, $executionId, $definitionId);

        return $this->drain($executionId);
    }

    /**
     * Resumes a persisted run from file state: rebuilds the context, queues
     * the next runnable step of its workflow, and drains again.
     */
    public function resume(ArazzoDocument $document, string $executionId): CliRunResult
    {
        $this->runner->resume($document, $executionId);

        return $this->drain($executionId);
    }

    private function drain(string $executionId): CliRunResult
    {
        $runSpan = $this->tracer->startSpan('arazzo.workflow.run', [
            'execution.id' => $executionId,
        ]);

        try {
            $result = $this->drainUnderSpan($executionId);
            $runSpan->setStatus(!$result->failed());

            return $result;
        } finally {
            $runSpan->end();
        }
    }

    private function drainUnderSpan(string $executionId): CliRunResult
    {
        $processed = 0;

        while ($this->queue->dispatched !== []) {
            if ($processed++ >= self::MAX_QUEUED_STEPS) {
                throw CliExecutionException::maxQueuedStepsExceeded($executionId, self::MAX_QUEUED_STEPS);
            }

            $slot = array_shift($this->queue->dispatched);
            $job = $slot['job'];

            if ($job instanceof ExecuteStepJob) {
                $this->runner->handleStep($job);
            }
        }

        return CliRunResult::fromStore($executionId, $this->store);
    }
}
