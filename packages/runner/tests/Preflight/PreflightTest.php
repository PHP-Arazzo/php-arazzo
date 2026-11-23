<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Tests\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
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
