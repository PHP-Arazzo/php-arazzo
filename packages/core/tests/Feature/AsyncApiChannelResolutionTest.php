<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Core\Feature;

use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\PendingCorrelationRegistryInterface;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\AsyncApiStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\AsyncApiOperationResolver;
use Alama\Arazzo\Sources\Sources;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('dereferences AsyncAPI channelPath in a valid Arazzo fixture to concrete server URL', function (): void {
    $fixturePath = realpath(__DIR__.'/../fixtures/valid/v1.1.0/asyncapi-channelpath.arazzo.yaml');
    expect($fixturePath)->not->toBeFalse();

    $doc = Document::create()->load($fixturePath);

    $sources = Sources::create();
    $resolver = AsyncApiOperationResolver::create($sources, dirname($fixturePath));

    $workflow = $doc->workflows->all()[0];
    $steps = $workflow->steps->all();
    $sendStep = $steps[0];
    $receiveStep = $steps[1];

    $resolvedSend = $resolver->resolve($sendStep, $doc);
    expect($resolvedSend->normalized->path)->toBe('user/events')
        ->and($resolvedSend->normalized->resolvedServerUrl)->toBe('https://broker.example.com/v1')
        ->and($resolvedSend->operationName)->toBe('userEvents');

    $context = new WorkflowContext('def-1', ['orderId' => 'ord-42'], [], [], 'async-channel-workflow', 'exec-1');
    $concreteUrl = $resolver->resolveTargetUrl($sendStep, $context, $doc);
    expect($concreteUrl)->toBe('https://broker.example.com/v1/user/events');

    // Test send step execution against concrete URL
    $client = new class() implements HttpClientInterface
    {
        public ?RequestInterface $sent = null;

        public function sendRequest(RequestInterface $request, ?float $timeoutSeconds = null): ResponseInterface
        {
            $this->sent = $request;

            return new Response(202);
        }
    };

    $pendingCorrelations = new class() implements PendingCorrelationRegistryInterface
    {
        /** @var list<array{correlationId: string, channelPath: string}> */
        public array $created = [];

        public function create(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
        {
            $this->created[] = compact('correlationId', 'channelPath');
        }

        public function findByCorrelationId(string $correlationId): ?PendingCorrelation
        {
            return null;
        }

        public function consume(string $correlationId): void {}

        public function existsForExecution(string $executionId): bool
        {
            return false;
        }
    };

    $httpFactory = new HttpFactory();
    $executor = new AsyncApiStepExecutor(
        $pendingCorrelations,
        new EvaluationEngine(expression: new ExpressionEngine()),
        $client,
        $httpFactory,
        $httpFactory,
        $httpFactory,
        $resolver,
    );

    $sendOutcome = $executor->execute($sendStep, $context, $doc, 'exec-1');
    expect($sendOutcome->statusCode)->toBe(202)
        ->and($client->sent)->not->toBeNull()
        ->and((string) $client->sent->getUri())->toBe('https://broker.example.com/v1/user/events');

    // Test receive step execution storing concrete URL
    $receiveOutcome = $executor->execute($receiveStep, $context, $doc, 'exec-1');
    expect($receiveOutcome->suspended)->toBeTrue()
        ->and($pendingCorrelations->created)->toHaveCount(1)
        ->and($pendingCorrelations->created[0]['correlationId'])->toBe('ord-42')
        ->and($pendingCorrelations->created[0]['channelPath'])->toBe('https://broker.example.com/v1/user/events');
});
