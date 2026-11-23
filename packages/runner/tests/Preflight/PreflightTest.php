<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Tests\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;

it('validates steps using registered operation resolvers', function (): void {
    $resolver = new class() implements OperationResolverInterface
    {
        public function supportsStep(Step $step, ArazzoDocument $document): bool
        {
            return $step->target->operationId === 'validOp';
        }

        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
        {
            return new ResolvedOperation(
                source: new SourceDescription('src', 'http://example.com', SourceType::Openapi),
                normalized: new NormalizedOpenApiOperation('/test', 'get', null, [], [], [], [], [], []),
                rpcProtocol: null,
                operationName: null,
                rpcMethod: null,
                graphqlOperation: null,
                interaction: null,
            );
        }
    };

    $preflight = new Preflight(new OperationResolvers([$resolver]));

    $doc = new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'wf1',
                summary: null,
                description: null,
                inputs: null,
                dependsOn: [],
                steps: [
                    new Step('s1', null, new StepTarget(operationId: 'validOp'), new StepFlow(), new StepIo()),
                    new Step('s2', null, new StepTarget(operationId: 'unsupportedOp'), new StepFlow(), new StepIo()),
                ],
                successActions: [],
                failureActions: [],
                outputs: [],
                parameters: [],
            ),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $result = $preflight->validate($doc);
    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe('preflight.no_resolver');
});

it('rejects unsupported XPath selector versions on parameters and outputs, and throws on empty OperationResolvers::resolve()', function (): void {
    $resolvers = new OperationResolvers([]);
    $preflight = new Preflight($resolvers);

    $step = new Step(
        's1',
        null,
        new StepTarget(),
        new StepFlow(),
        new StepIo(
            parameters: [
                new Parameter('p1', ParameterIn::Query, new Selector(null, '//item', ExpressionType::XPath, 'xpath-30')),
            ],
            outputs: [
                'out' => new Selector(null, '//id', ExpressionType::XPath, 'xpath-20'),
            ],
        ),
    );
    $doc = new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [new Workflow('wf1', null, null, null, [], [$step], [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $result = $preflight->validate($doc);
    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors[0]->code)->toBe('preflight.unsupported_selector_version');

    expect(fn () => $resolvers->resolve($step, $doc))->toThrow(\RuntimeException::class);
});
