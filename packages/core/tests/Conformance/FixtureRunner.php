<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Conformance;

use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Executes a fixture through the SYNCHRONOUS adapter (WorkflowExecutor
 * driving every step in-process).
 */
final class FixtureRunner extends ConformanceHarness
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

        $runtime = $this->runtime($this->sourceRegistry);
        $engine = $this->engine();

        $httpPlugin = new HttpStepExecutor(
            new DefaultOpenApiExecutor($this->http, new HttpFactory()),
            $runtime->operations,
            $engine,
            $this->outputExtractor($runtime),
            $this->schemaValidator($runtime),
        );

        $executor = new WorkflowExecutor(
            new StepExecutor($engine, localExecutors: [$httpPlugin]),
            new WorkflowEngine($engine),
            events: $this->events,
        );

        try {
            $executor->execute($document->firstWorkflow(), $document, $fixture['inputs'] ?? []);
        } catch (\Throwable) {
            // Terminal failures are observed through the event stream.
        }

        return $this->observe();
    }
}
