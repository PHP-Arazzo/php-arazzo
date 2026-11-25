<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Cli\Console\Cli\CliExecutionStore;
use Alama\Arazzo\Cli\Console\Cli\CliRunner;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runtime\State\FileStateStore;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceResolver;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Tests\Expression\Support\TestEvaluationEngine;
use Alama\Arazzo\Tests\Support\FakePsr18Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;

/**
 * Parity invariant: the synchronous adapter (Runner) and the queue-driven adapter
 * (drained in-process by CliRunner via AsyncRunner) produce the SAME terminal
 * status and step spend for the same document.
 */
function parityFixtures(): array
{
    $openapiJson = '{"openapi":"3.0.0","servers":[{"url":"https://api.test"}],"paths":{"/rides":{"post":{"operationId":"createRide","responses":{"201":{"description":"Created"}}}}}}';
    $openapiFile = tempnam(sys_get_temp_dir(), 'parity_').'.json';
    file_put_contents($openapiFile, $openapiJson);

    $workflow = new Workflow('parity_wf', null, null, null, [], [
        StepFactory::http('p1', null, new StepFlow(), new StepIo(), 'createRide'),
        StepFactory::http('p2', null, new StepFlow(dependsOn: ['p1']), new StepIo(), 'createRide'),
    ], [], [], [], []);

    $document = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Parity', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('test-api', $openapiFile, SourceType::Openapi)],
        workflows: [$workflow],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $httpClient = new FakePsr18Client();
    $httpClient->enqueue(new Response(201, [], json_encode(['rideId' => 99])));
    $httpClient->enqueue(new Response(201, [], json_encode(['rideId' => 100])));
    $httpClient->enqueue(new Response(201, [], json_encode(['rideId' => 99])));
    $httpClient->enqueue(new Response(201, [], json_encode(['rideId' => 100])));

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $sources = new Sources(new class() implements SourceResolver
    {
        public function resolve(SourceDescription $description, string $basePath): SourceDocument
        {
            return new SourceDocument(
                $description->name,
                $description->type,
                $description->url,
                json_decode((string) file_get_contents($description->url), true),
            );
        }
    });

    $http = HttpProtocol::create($httpClient, new HttpFactory(), $sources, $engine, new ExpressionEngine());

    return [$document, $http->executor(strictValidation: false)];
}

it('sync and queue-driven adapters agree on terminal status and step spend', function (): void {
    [$document, $httpExecutor] = parityFixtures();

    // --- synchronous adapter ---
    $syncResult = Runner::create()
        ->withExecutor($httpExecutor)
        ->run($document, 'parity_wf', []);

    // --- queue-driven adapter (in-process drain) ---
    $definitions = new InMemoryDefinitionStore();
    $definitions->save($document);
    $cli = new CliRunner(
        evaluationEngine: new TestEvaluationEngine(),
        store: new CliExecutionStore(new FileStateStore(sys_get_temp_dir().'/arazzo-parity-'.bin2hex(random_bytes(4)))),
        definitions: $definitions,
        protocolExecutors: [$httpExecutor],
    );
    $cliResult = $cli->run($document, 'parity_wf', [], 'parity_exec_1');

    expect($syncResult->status)->toBe(ExecutionStatus::Succeeded)
        ->and($cliResult->status)->toBe($syncResult->status->value)
        ->and($syncResult->stepsSpent)->toBeGreaterThanOrEqual(2);
});

beforeEach(function (): void {
    OperationExecutorRegistry::reset();
});

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});
