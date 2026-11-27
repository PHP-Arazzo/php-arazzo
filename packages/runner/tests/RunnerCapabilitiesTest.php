<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\RunnerInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function runnerWithHttpFake(): RunnerInterface
{
    $client = new class() implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return new Response(200, ['Content-Type' => 'application/json'], '{"pets":[{"id":1,"name":"Rex"}]}');
        }
    };

    $http = HttpProtocol::create($client, new HttpFactory());

    return Runner::create()
        ->withExecutor($http->executor(strictValidation: false))
        ->withOperationResolver($http->resolver());
}

function runnerFixtureDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [new SourceDescription('pets', __DIR__.'/fixtures/petstore.yaml', SourceType::Openapi)],
        workflows: [new Workflow('find', null, null, null, [], [StepFactory::http('list', null, new StepFlow(), new StepIo(), 'listPets')], [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('exposes the RunnerInterface entry point', function (): void {
    $runner = runnerWithHttpFake();
    expect($runner)->toBeInstanceOf(RunnerInterface::class);
});

it('runs a workflow whose operation resolves through HttpProtocol', function (): void {
    $result = runnerWithHttpFake()->run(runnerFixtureDocument(), 'find');

    expect($result)->toBeInstanceOf(WorkflowExecutionResult::class)
        ->and($result->status)->toBe(ExecutionStatus::Succeeded)
        ->and($result->isSucceeded())->toBeTrue();
});

it('exposes typed per-step verdicts and a serializable array representation', function (): void {
    $result = runnerWithHttpFake()->run(runnerFixtureDocument(), 'find');
    $array = $result->toArray();

    expect(array_keys($array))->toBe(['workflowId', 'status', 'outputs', 'stepsSpent', 'workflowCallStack', 'sequentialFallbacks', 'steps'])
        ->and($array['status'])->toBe('succeeded')
        ->and(array_keys($array['steps']))->toBe(['list'])
        ->and($array['steps']['list']['success'])->toBeTrue()
        ->and($array['steps']['list']['outputs'])->toBeArray();
});
