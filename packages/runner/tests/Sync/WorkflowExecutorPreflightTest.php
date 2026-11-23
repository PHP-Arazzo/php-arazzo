<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Tests\Sync;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Support\Events\Dispatcher\SimpleEventDispatcher;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Runner\Events\RunStartedEvent;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;

require_once __DIR__.'/../Support/FakeSeams.php';

it('guards the synchronous adapter before any side effect or event fires', function (): void {
    $events = new SimpleEventDispatcher();
    $events->subscribe(RunStartedEvent::class, function () {
        $this->fail('preflight must fail before RunStartedEvent fires');
    });

    $document = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('P', null, null, '1.0.0'),
        sourceDescriptions: [
            new SourceDescription('other', 'https://conformance.invalid/other.json', SourceType::Openapi),
        ],
        workflows: [
            new Workflow(
                workflowId: 'wf',
                summary: null,
                description: null,
                inputs: null,
                dependsOn: [],
                steps: [
                    new Step('fetch', null, new StepTarget(operationId: 'unresolvableOp'), new StepFlow(), new StepIo()),
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

    $resolver = \Mockery::mock(OpenApiOperationResolver::class);
    $resolver->shouldReceive('supportsStep')->andReturn(false);

    $preflight = new Preflight(new OperationResolvers([$resolver]));
    $engine = new EvaluationEngine(expression: new ExpressionEngine());

    $executor = new WorkflowExecutor(
        new StepExecutor($engine),
        new WorkflowEngine($engine),
        events: $events,
        preflight: $preflight,
    );

    try {
        $executor->execute($document->workflows[0], $document, []);
        $this->fail('expected PreflightFailureException');
    } catch (PreflightFailureException $e) {
        expect($e->result->errors)->not->toBeEmpty()
            ->and($e->result->errors[0]->code)->toBe('preflight.no_resolver');
    }
});
