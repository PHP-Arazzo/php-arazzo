<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Conformance;

use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\Parser\Decoders\SymfonyYamlDecoder;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Plugins\OperationExecutor\SubWorkflowStepExecutor;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Alama\Arazzo\Tests\Support\FakeLockManager;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Queued-adapter counterpart of OaiFixtureRunner: documents containing
 * sub-workflow steps (step.workflowId) execute through StepExecutionWorker,
 * whose protocol list includes SubWorkflowStepExecutor.
 */
final class OaiQueueFixtureRunner extends ConformanceHarness
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture): array
    {
        $path = (string) $fixture['arazzoFile'];
        $decoder = new SymfonyYamlDecoder();
        $decoded = $decoder->decode((string) file_get_contents($path));

        $sources = [];

        foreach (OaiCorpusRunner::localSources($path) as $name => $sourceDocument) {
            $sources[$name] = $sourceDocument->content;
        }

        $inputs = $fixture['inputs'] ?? [];

        if ($inputs === []) {
            $schema = is_array($decoded['workflows'][0]['inputs'] ?? null) ? $decoded['workflows'][0]['inputs'] : [];

            if ($schema !== []) {
                $inputs = ConformanceFabricator::objectFromSchema($schema);
            }
        }

        $document = $this->prepare([
            'name' => (string) ($fixture['name'] ?? basename($path)),
            'arazzo' => $decoded,
            'sources' => $sources,
            'responses' => $fixture['responses'] ?? [],
            'inputs' => $inputs,
        ]);

        if ($document->firstWorkflow() === null) {
            throw new \InvalidArgumentException('Document has no workflows');
        }

        $workflow = $document->firstWorkflow();
        $runtime = $this->runtime($this->sourceRegistry);
        $engine = $this->engine();

        $definitions = new InMemoryDefinitionStore();
        $definitionId = $definitions->save($document);
        $queue = new SyncQueueDriver();

        OperationExecutorRegistry::reset();
        $executors = OperationExecutorRegistry::create();
        $httpPlugin = new HttpStepExecutor(
            new FakerOpenApiExecutor(
                new DefaultOpenApiExecutor($this->http, new HttpFactory()),
                FakerOpenApiExecutor::referencedBodyFields((string) file_get_contents($path)),
            ),
            $runtime->operations,
            $engine,
            $this->outputExtractor($runtime),
            $this->schemaValidator($runtime),
        );
        $executors->register($httpPlugin);
        $executors->register(new SubWorkflowStepExecutor(
            new WorkflowExecutor(
                new StepExecutor($engine, executors: $executors),
                new WorkflowEngine($engine),
            ),
            $engine,
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

        $executionId = 'oai_'.bin2hex(random_bytes(4));
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
            // Observed through the event stream.
        } finally {
            OperationExecutorRegistry::reset();
        }

        return $this->observe();
    }
}
