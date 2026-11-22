<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Support\Events\Dispatcher\SimpleEventDispatcher;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Events\RunStartedEvent;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Sync\StepExecutor;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\SourceGraph;
use Alama\Arazzo\Tests\Support\FakePsr18Client;
use GuzzleHttp\Psr7\HttpFactory;

const INPUTS_SCHEMA_DOC = __DIR__.'/../fixtures/inputs-schema/workflow.arazzo.yaml';

it('accepts inputs matching the declared schema', function (): void {
    $document = Document::create()->load(INPUTS_SCHEMA_DOC);
    $validator = preflightForInputsDoc();

    $result = $validator->validateInputs($document, 'bookRide', ['rideId' => 42, 'rider' => 'sam']);

    expect($result->isValid())->toBeTrue(json_encode($result->errors));
});

it('rejects missing required and wrong-typed inputs before execution', function (): void {
    $document = Document::create()->load(INPUTS_SCHEMA_DOC);
    $validator = preflightForInputsDoc();

    // rideId as string violates the integer type; rider is not required.
    $result = $validator->validateInputs($document, 'bookRide', ['rideId' => 'not-an-int']);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors[0]->code)->toBe('preflight.inputs_schema')
        ->and($result->errors[0]->path)->toStartWith('/workflows/bookRide/inputs');
});

it('treats documents without an inputs schema as unconstrained', function (): void {
    $document = Document::create()->load(__DIR__.'/../fixtures/loader/minimal.yaml');
    $validator = preflightForInputsDoc();

    $result = $validator->validateInputs($document, 'wf', ['anything' => ['goes' => true]]);

    expect($result->isValid())->toBeTrue();
});

it('blocks executor runs on invalid inputs before any event fires', function (): void {
    $events = new SimpleEventDispatcher();
    $fired = [];
    $events->subscribe(RunStartedEvent::class, function (object $e) use (&$fired): void {
        $fired[] = $e::class;
    });

    $document = Document::create()->load(INPUTS_SCHEMA_DOC);

    $engine = new EvaluationEngine(expression: new ExpressionEngine());
    $runtime = SourceGraph::runtime(new DefaultSourceResolver([]));
    $documents = $runtime->document;
    $outputExtractor = new StepOutputExtractor($runtime->operations, $engine, new ExpressionEngine());
    $schemaValidator = new ResponseSchemaValidator($runtime->operations);

    $executor = new WorkflowExecutor(
        new StepExecutor(
            new DefaultOpenApiExecutor(new FakePsr18Client(), new HttpFactory()),
            $runtime->operations,
            $engine,
            $outputExtractor,
            $schemaValidator,
        ),
        workflowEngine: new WorkflowEngine($engine),
        events: $events,
        preflight: new Preflight(new OperationResolvers([$runtime->operations])),
        document: $documents,
    );

    try {
        $executor->execute(
            $document->workflows[0],
            $document,
            ['rideId' => 'wrong-type'],
        );

        $this->fail('expected PreflightFailureException');
    } catch (PreflightFailureException $e) {
        expect($e->result->errors[0]->code)->toBe('preflight.inputs_schema')
            ->and($fired)->toBe([]); // nothing executed, not even RunStartedEvent
    }
});

function preflightForInputsDoc(): DocumentInterface
{
    return SourceGraph::using();
}
