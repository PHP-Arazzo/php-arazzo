<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Conformance;

use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Tests\Support\FakeLockManager;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Executes a fixture through the QUEUED adapter: every step runs through
 * StepExecutionWorker, and follow-up steps are driven by draining the
 * recorded queue until the run terminates.
 */
final class QueueFixtureRunner extends ConformanceHarness
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture): array
    {
        $document = $this->prepare($fixture);

        if ($document->firstWorkflow() === null) {
            throw new \InvalidArgumentException('Fixture has no workflows');
        }

        $workflow = $document->firstWorkflow();
        $runtime = $this->runtime($this->sourceRegistry);

        $definitions = new InMemoryDefinitionStore();
        $definitionId = $definitions->save($document);
        $queue = new SyncQueueDriver();

        $engine = $this->engine();

        OperationExecutorRegistry::reset();
        $executors = OperationExecutorRegistry::create();
        $executors->register(new HttpStepExecutor(
            new DefaultOpenApiExecutor($this->http, new HttpFactory()),
            $runtime->operations,
            $engine,
            $this->outputExtractor($runtime),
            $this->schemaValidator($runtime),
        ));

        $ports = new AsyncPorts(
            store: new InMemoryExecutionStore(),
            definitions: $definitions,
            queueDriver: $queue,
            lockManager: new FakeLockManager(),
        );

        $worker = new StepExecutionWorker(
            $ports,
            $engine,
            $executors,
            new RunControlFlow(new WorkflowEngine($engine), $queue, new ObservabilityContext(events: $this->events)),
        );

        $executionId = 'parity_'.bin2hex(random_bytes(4));
        $context = new WorkflowContext(
            definitionId: $definitionId,
            inputs: $fixture['inputs'] ?? [],
            workflowId: $workflow->workflowId,
            executionId: $executionId,
        );
        $queue->dispatch(new ExecuteStepJob($workflow->firstStep(), $context));

        try {
            while ($queued = array_shift($queue->dispatched)) {
                /** @var ExecuteStepJob $job */
                $job = $queued['job'];
                $worker->handle($job);
            }
        } catch (\Throwable) {
            // Terminal failures are observed through the event stream.
        } finally {
            OperationExecutorRegistry::reset();
        }

        return $this->observe();
    }
}
