<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Expression\Data\EvaluationContext;

test('evaluation context hosts step-record domain queries (Tell, Dont Ask)', function (): void {
    $context = new WorkflowContext(
        definitionId: 'wf',
        steps: [
            's1' => [
                'inputs' => ['name' => 'alice'],
                'outputs' => ['result' => 42],
                'request' => ['method' => 'GET', 'url' => 'https://api.example.com', 'headers' => ['X-Token' => 'abc']],
                'response' => ['statusCode' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => ['id' => 1]],
            ],
            's2' => ['inputs' => [], 'outputs' => []],
            // Non-array step record
            's3' => 'corrupt',
            // Non-array response / request
            's4' => ['response' => 'not-an-array'],
            's5' => ['request' => 'not-an-array'],
            // Non-array body
            's6' => ['response' => ['body' => 'not-an-array']],
            's7' => ['request' => ['body' => 'not-an-array']],
            // Non-array outputs / inputs / headers
            's8' => ['outputs' => 'not-an-array'],
            's9' => ['inputs' => 'not-an-array'],
            's10' => ['response' => ['headers' => 'not-an-array']],
            // Valid body arrays
            's11' => ['request' => ['body' => ['key' => 'val']]],
        ],
    );

    $withStep = new EvaluationContext($context, currentStepId: 's1');
    $withoutStep = new EvaluationContext($context, currentStepId: null);
    $missingStep = new EvaluationContext($context, currentStepId: 'missing');

    // Getters
    expect($withStep->getWorkflowContext())->toBe($context);
    expect($withStep->getCurrentStepId())->toBe('s1');
    expect($withStep->getDocument())->toBeNull();

    // defaultRootBody: current step response body, or [] when unavailable
    expect($withStep->defaultRootBody())->toEqual(['id' => 1]);
    expect($withoutStep->defaultRootBody())->toEqual([]);
    expect($missingStep->defaultRootBody())->toEqual([]);
    $ctxS3 = new EvaluationContext($context, currentStepId: 's3');
    expect($ctxS3->defaultRootBody())->toEqual([]);
    $ctxS4 = new EvaluationContext($context, currentStepId: 's4');
    expect($ctxS4->defaultRootBody())->toEqual([]);

    // stepResponse / stepRequest
    expect($withStep->stepResponse('s1'))->toEqual(['statusCode' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => ['id' => 1]]);
    expect($withStep->stepResponse('missing'))->toBeNull();
    expect($ctxS4->stepResponse('s4'))->toBeNull();
    expect($withStep->stepRequest('s1'))->toEqual(['method' => 'GET', 'url' => 'https://api.example.com', 'headers' => ['X-Token' => 'abc']]);
    expect($withStep->stepRequest('missing'))->toBeNull();
    expect($ctxS4->stepRequest('s5'))->toBeNull();

    // responseBodyForStep / requestBodyForStep
    expect($withStep->responseBodyForStep('s1'))->toEqual(['id' => 1]);
    expect($withStep->responseBodyForStep('missing'))->toEqual([]);
    expect($ctxS4->responseBodyForStep('s4'))->toEqual([]);
    $ctxS6 = new EvaluationContext($context);
    expect($ctxS6->responseBodyForStep('s6'))->toEqual([]);
    expect($withStep->requestBodyForStep('s1'))->toEqual([]);
    expect($withStep->requestBodyForStep('missing'))->toEqual([]);
    expect($ctxS4->requestBodyForStep('s5'))->toEqual([]);
    $ctxS7 = new EvaluationContext($context);
    expect($ctxS7->requestBodyForStep('s7'))->toEqual([]);
    $ctxS11 = new EvaluationContext($context);
    expect($ctxS11->requestBodyForStep('s11'))->toEqual(['key' => 'val']);

    // httpMetaForStep: statusCode, method, url, unknown field
    expect($withStep->httpMetaForStep('s1', 'statusCode'))->toBe(200);
    expect($withStep->httpMetaForStep('s1', 'method'))->toBe('GET');
    expect($withStep->httpMetaForStep('s1', 'url'))->toBe('https://api.example.com');
    expect($withStep->httpMetaForStep('s1', 'nope'))->toBeNull();
    expect($withStep->httpMetaForStep('missing', 'statusCode'))->toBeNull();
    // Non-array response / request -> null via responseField / requestField
    expect($ctxS4->httpMetaForStep('s4', 'statusCode'))->toBeNull();
    expect($ctxS4->httpMetaForStep('s5', 'method'))->toBeNull();
    expect($ctxS4->httpMetaForStep('s5', 'url'))->toBeNull();

    // stepOutputs / stepInputs
    expect($withStep->stepOutputs('s1'))->toEqual(['result' => 42]);
    expect($withStep->stepOutputs('missing'))->toEqual([]);
    $ctxS8 = new EvaluationContext($context);
    expect($ctxS8->stepOutputs('s8'))->toEqual([]);
    expect($withStep->stepInputs('s1'))->toEqual(['name' => 'alice']);
    expect($withStep->stepInputs('missing'))->toEqual([]);
    expect($ctxS8->stepInputs('s9'))->toEqual([]);

    // stepResponseHeader: case-insensitive lookup
    expect($withStep->stepResponseHeader('s1', 'CONTENT-TYPE'))->toBe('application/json');
    expect($withStep->stepResponseHeader('s1', 'content-type'))->toBe('application/json');
    expect($withStep->stepResponseHeader('s1', 'x-token'))->toBeNull(); // request header, not response
    expect($withStep->stepResponseHeader('missing', 'content-type'))->toBeNull();
    // Non-array headers -> null
    $ctxS10 = new EvaluationContext($context);
    expect($ctxS10->stepResponseHeader('s10', 'content-type'))->toBeNull();
});
