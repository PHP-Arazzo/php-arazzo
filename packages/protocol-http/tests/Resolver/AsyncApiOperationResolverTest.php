<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiResolutionException;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiStepException;
use Alama\Arazzo\Protocol\Http\Resolver\AsyncApiOperationResolver;
use Alama\Arazzo\Sources\Sources;

function createAsyncApiTestDocument(): ArazzoDocument
{
    $source = new SourceDescription('ordersBroker', 'https://specs.example.com/asyncapi.json', SourceType::Asyncapi);

    return new ArazzoDocument(
        '1.0.0',
        new Info('Test Workflow', null, null, '1.0'),
        [$source],
        [],
        new Components([], [], [], []),
        [],
        null,
        SpecVersion::V1_1,
    );
}

function createAsyncApiSourceDoc(): SourceDocument
{
    return new SourceDocument(
        'ordersBroker',
        SourceType::Asyncapi,
        'https://specs.example.com/asyncapi.json',
        [
            'asyncapi' => '3.0.0',
            'servers' => [
                'prod' => [
                    'host' => 'broker.local:9000',
                    'pathname' => '/v1',
                    'protocol' => 'https',
                ],
            ],
            'channels' => [
                'ordersChannel' => [
                    'address' => 'orders/{region}/created',
                ],
            ],
        ],
    );
}

it('supports steps with async actions or channelPath', function (): void {
    $resolver = AsyncApiOperationResolver::create();
    $doc = createAsyncApiTestDocument();

    $asyncStep = new Step('s1', null, StepTarget::async('send', 'https://example.com'), new StepFlow(), new StepIo());
    $plainStep = new Step('s2', null, StepTarget::http('op1'), new StepFlow(), new StepIo());

    expect($resolver->supportsStep($asyncStep, $doc))->toBeTrue()
        ->and($resolver->supportsStep($plainStep, $doc))->toBeFalse();
});

it('resolves raw absolute HTTP URLs directly without source lookup', function (): void {
    $resolver = AsyncApiOperationResolver::create();
    $doc = createAsyncApiTestDocument();

    $step = new Step('s1', null, StepTarget::async('send', 'https://direct.broker.com/events'), new StepFlow(), new StepIo());
    $resolved = $resolver->resolve($step, $doc);

    expect($resolved->normalized->resolvedServerUrl)->toBe('https://direct.broker.com/events')
        ->and($resolved->binding())->toBe('asyncapi');
});

it('resolves standard Arazzo 1.1 channelPath and interpolates path parameters', function (): void {
    $sourceDoc = createAsyncApiSourceDoc();
    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $doc = createAsyncApiTestDocument();

    $step = new Step(
        's1',
        null,
        StepTarget::async('send', '{$sourceDescriptions.ordersBroker.url}#/channels/ordersChannel'),
        new StepFlow(),
        new StepIo(parameters: [
            new Parameter('region', ParameterIn::Path, 'eu-west-1'),
        ]),
    );

    $resolved = $resolver->resolve($step, $doc);
    expect($resolved->normalized->path)->toBe('orders/{region}/created')
        ->and($resolved->normalized->resolvedServerUrl)->toBe('https://broker.local:9000/v1');

    $context = new WorkflowContext('def1', [], [], [], 'wf1', 'exec1');
    $concreteUrl = $resolver->resolveTargetUrl($step, $context, $doc);
    expect($concreteUrl)->toBe('https://broker.local:9000/v1/orders/eu-west-1/created');
});

it('resolves dotted reference syntax $sourceDescriptions.ordersBroker.ordersChannel', function (): void {
    $sourceDoc = createAsyncApiSourceDoc();
    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $doc = createAsyncApiTestDocument();

    $step = new Step(
        's1',
        null,
        StepTarget::async('receive', '$sourceDescriptions.ordersBroker.ordersChannel'),
        new StepFlow(),
        new StepIo(),
    );

    $resolved = $resolver->resolve($step, $doc);
    expect($resolved->normalized->path)->toBe('orders/{region}/created');
});

it('throws AsyncApiResolutionException when source or channel is missing', function (): void {
    $sourceDoc = createAsyncApiSourceDoc();
    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $doc = createAsyncApiTestDocument();

    $missingChannelStep = new Step(
        's1',
        null,
        StepTarget::async('send', '{$sourceDescriptions.ordersBroker.url}#/channels/nonExistent'),
        new StepFlow(),
        new StepIo(),
    );

    expect(fn () => $resolver->resolve($missingChannelStep, $doc))
        ->toThrow(AsyncApiResolutionException::class);

    $missingSourceStep = new Step(
        's2',
        null,
        StepTarget::async('send', '{$sourceDescriptions.missingBroker.url}#/channels/ordersChannel'),
        new StepFlow(),
        new StepIo(),
    );

    expect(fn () => $resolver->resolve($missingSourceStep, $doc))
        ->toThrow(AsyncApiResolutionException::class);
});

it('resolves bare pointers, bare names, and rejects unresolvable targets when multiple sources exist', function (): void {
    $sourceDoc = createAsyncApiSourceDoc();
    $sources = Sources::create()->withDocument($sourceDoc);
    $resolver = AsyncApiOperationResolver::create($sources);
    $doc = createAsyncApiTestDocument();

    // 1. Bare pointer #/channels/ordersChannel
    $barePointerStep = new Step('s1', null, StepTarget::async('send', '#/channels/ordersChannel'), new StepFlow(), new StepIo());
    $resolved1 = $resolver->resolve($barePointerStep, $doc);
    expect($resolved1->normalized->path)->toBe('orders/{region}/created');

    // 2. Bare name ordersChannel
    $bareNameStep = new Step('s2', null, StepTarget::async('send', 'ordersChannel'), new StepFlow(), new StepIo());
    $resolved2 = $resolver->resolve($bareNameStep, $doc);
    expect($resolved2->normalized->path)->toBe('orders/{region}/created');

    // 3. Document with multiple asyncapi sources cannot resolve bare name
    $secondSource = new SourceDescription('secondBroker', 'https://specs.example.com/second.json', SourceType::Asyncapi);
    $multiSourceDoc = new ArazzoDocument(
        '1.0.0',
        new Info('Multi Source', null, null, '1.0'),
        [...$doc->sourceDescriptions, $secondSource],
        [],
        new Components([], [], [], []),
        [],
        null,
        SpecVersion::V1_1,
    );

    expect(fn () => $resolver->resolve($bareNameStep, $multiSourceDoc))
        ->toThrow(AsyncApiResolutionException::class);
});

it('guards against missing channelPath and handles document load failure', function (): void {
    $resolver = AsyncApiOperationResolver::create();
    $doc = createAsyncApiTestDocument();

    $nullSendStep = new Step('s1', null, StepTarget::async('send', ''), new StepFlow(), new StepIo());
    expect(fn () => $resolver->resolve($nullSendStep, $doc))
        ->toThrow(AsyncApiStepException::class);

    $nullReceiveStep = new Step('s2', null, StepTarget::async('receive', ''), new StepFlow(), new StepIo());
    expect(fn () => $resolver->resolve($nullReceiveStep, $doc))
        ->toThrow(AsyncApiStepException::class);

    // Document load failure wraps in AsyncApiResolutionException
    $failingResolver = AsyncApiOperationResolver::create(Sources::create());
    $failStep = new Step('s3', null, StepTarget::async('send', '{$sourceDescriptions.ordersBroker.url}#/channels/ordersChannel'), new StepFlow(), new StepIo());
    expect(fn () => $failingResolver->resolve($failStep, $doc))
        ->toThrow(AsyncApiResolutionException::class);
});

it('interpolates path parameters with expressions, handles empty parameters, and returns path when server is null', function (): void {
    $docWithoutServer = new SourceDocument(
        'ordersBroker',
        SourceType::Asyncapi,
        'https://specs.example.com/asyncapi.json',
        [
            'asyncapi' => '3.0.0',
            'channels' => [
                'ordersChannel' => [
                    'address' => 'orders/{region}/created',
                ],
            ],
        ],
    );

    $sources = Sources::create()->withDocument($docWithoutServer);
    $resolver = AsyncApiOperationResolver::create($sources);
    $doc = createAsyncApiTestDocument();

    // 1. Step with no parameters returns un-interpolated path and no server URL
    $stepNoParams = new Step('s1', null, StepTarget::async('send', 'ordersChannel'), new StepFlow(), new StepIo());
    $context = new WorkflowContext('def1', ['region' => 'us-east-1'], [], [], 'wf1', 'exec1');
    $concreteUrl = $resolver->resolveTargetUrl($stepNoParams, $context, $doc);
    expect($concreteUrl)->toBe('orders/{region}/created');

    // 2. Step with expression parameter
    $stepWithExpr = new Step(
        's2',
        null,
        StepTarget::async('send', 'ordersChannel'),
        new StepFlow(),
        new StepIo(parameters: [
            new Parameter('region', ParameterIn::Path, new Expression('$inputs.region')),
        ]),
    );

    $concreteWithExpr = $resolver->resolveTargetUrl($stepWithExpr, $context, $doc);
    expect($concreteWithExpr)->toBe('orders/us-east-1/created');
});
