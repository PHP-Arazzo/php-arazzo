<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\PendingCorrelationRegistryInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\EngineException;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiResolutionException;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiStepException;
use Alama\Arazzo\Protocol\Http\Exceptions\OpenApiResolutionException;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\AsyncApiStepExecutor;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('protocol-http domain exception factories construct typed exceptions', function (): void {
    $cause = new JsonException('bad utf8');

    expect(OpenApiResolutionException::operationNotFound('getPet', 'petstore'))
        ->toBeInstanceOf(EngineException::class)
        ->and(OpenApiResolutionException::sourceNotFound('petstore', 's1')->getMessage())->toContain('petstore')
        ->and(OpenApiResolutionException::documentLoadFailed('petstore')->getMessage())->toContain('petstore')
        ->and(OpenApiResolutionException::invalidOperationPath('/bad')->getMessage())->toContain('/bad')
        ->and(AsyncApiStepException::unsupportedSpecVersion('s1'))->toBeInstanceOf(LogicException::class)
        ->and(AsyncApiStepException::unsupportedAction('poll', 's1')->getMessage())->toContain('poll')
        ->and(AsyncApiStepException::missingCorrelationId('s1')->getMessage())->toContain('correlationId')
        ->and(AsyncApiStepException::missingReceiveChannelPath('s1')->getMessage())->toContain('channelPath')
        ->and(AsyncApiStepException::missingSendChannelPath('s1')->getMessage())->toContain('channelPath')
        ->and(AsyncApiStepException::unencodablePayload('s1', $cause)->getPrevious())->toBe($cause)
        ->and(AsyncApiResolutionException::channelNotFound('orders', 'broker'))->toBeInstanceOf(EngineException::class)
        ->and(AsyncApiResolutionException::sourceNotFound('broker', 's1')->getMessage())->toContain('broker')
        ->and(AsyncApiResolutionException::documentLoadFailed('broker', $cause)->getPrevious())->toBe($cause)
        ->and(AsyncApiResolutionException::unresolvableChannelTarget('s1', 'orders')->getMessage())->toContain('orders');
});

test('AsyncApiStepExecutor guards metadata, factories, channel paths, actions, and payload encoding', function (): void {
    $registry = new class() implements PendingCorrelationRegistryInterface
    {
        public ?string $lastCorrelationId = null;

        public function create(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
        {
            $this->lastCorrelationId = $correlationId;
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

    $http = new class() implements HttpClientInterface
    {
        public function sendRequest(RequestInterface $request, ?float $timeoutSeconds = null): ResponseInterface
        {
            return new Response(202);
        }
    };

    $eval = new EvaluationEngine(expression: ExpressionEngine::create());
    $factory = new HttpFactory();

    $doc11 = new ArazzoDocument(
        arazzo: '1.1.0',
        info: new Info('Async', null, null, '1.0.0'),
        sourceDescriptions: [],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: SpecVersion::V1_1,
    );
    $ctx = new WorkflowContext('def', ['arr' => ['nested' => 1]], [], [], 'wf', 'exec-1');

    $noFactoryExecutor = new AsyncApiStepExecutor($registry, $eval, $http);
    expect($noFactoryExecutor->name())->toBe('asyncapi')
        ->and($noFactoryExecutor->priority())->toBe(AsyncApiStepExecutor::PLUGIN_PRIORITY);

    $unsupportedAction = new Step('s-bad-action', null, StepTarget::async('publish', 'https://broker.test/ch'), new StepFlow(), new StepIo());
    expect(fn () => $noFactoryExecutor->execute($unsupportedAction, $ctx, $doc11, 'exec-1'))
        ->toThrow(AsyncApiStepException::class);

    $recvNoChannel = new Step('s-no-chan', null, new StepTarget(action: 'receive', correlationId: new Expression('$inputs.arr')), new StepFlow(), new StepIo());
    expect(fn () => $noFactoryExecutor->execute($recvNoChannel, $ctx, $doc11, 'exec-1'))
        ->toThrow(AsyncApiStepException::class);

    $recvNonScalar = new Step('s-non-scalar', null, StepTarget::async('receive', '/events', new Expression('$inputs.arr')), new StepFlow(), new StepIo());
    $noFactoryExecutor->execute($recvNonScalar, $ctx, $doc11, 'exec-1');
    expect($registry->lastCorrelationId)->toBe('');

    $sendStep = new Step('s-send', null, StepTarget::async('send', 'https://broker.test/ch'), new StepFlow(), new StepIo());
    expect(fn () => $noFactoryExecutor->execute($sendStep, $ctx, $doc11, 'exec-1'))
        ->toThrow(ExecutionException::class);

    $noStreamExecutor = new AsyncApiStepExecutor($registry, $eval, $http, $factory);
    expect(fn () => $noStreamExecutor->execute($sendStep, $ctx, $doc11, 'exec-1'))
        ->toThrow(ExecutionException::class);

    $fullExecutor = new AsyncApiStepExecutor($registry, $eval, $http, $factory, $factory, $factory);

    $sendNoChannel = new Step('s-send-no-ch', null, new StepTarget(action: 'send'), new StepFlow(), new StepIo());
    expect(fn () => $fullExecutor->execute($sendNoChannel, $ctx, $doc11, 'exec-1'))
        ->toThrow(AsyncApiStepException::class);

    $sendRelativeChannel = new Step('s-send-rel', null, StepTarget::async('send', '/relative/ch'), new StepFlow(), new StepIo());
    expect(fn () => $fullExecutor->execute($sendRelativeChannel, $ctx, $doc11, 'exec-1'))
        ->toThrow(ExecutionException::class);

    $sendBadJson = new Step(
        's-bad-json',
        null,
        StepTarget::async('send', 'https://broker.test/ch'),
        new StepFlow(),
        new StepIo(requestBody: new RequestBody('application/json', ['bad' => "\xB1\x31"], [])),
    );
    expect(fn () => $fullExecutor->execute($sendBadJson, $ctx, $doc11, 'exec-1'))
        ->toThrow(AsyncApiStepException::class);
});
