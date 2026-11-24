<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;

it('declares the shared state DTOs')
    ->expect(class_exists(WorkflowContext::class))
    ->toBeTrue()
    ->and(class_exists(ExecutionState::class))
    ->toBeTrue();

it('round-trips every execution field through toArray and fromArray', function (): void {
    $state = new ExecutionState(
        executionId: 'exec_1',
        definitionId: 'definition_1',
        workflowId: 'workflow_1',
        currentStepId: 'step_1',
        inputs: ['nullable' => null, 'false' => false, 'empty' => []],
        stepAttempts: ['step_1' => 2],
        stepResults: ['step_1' => ['outputs' => ['answer' => 42]]],
        dependencies: ['step_2' => ['step_1']],
        outputs: ['done' => false],
        errors: [['message' => 'failed']],
        stepsSpent: 3,
        maxSteps: 10,
        workflowCallStack: ['workflow_1', 'workflow_2'],
        maxWorkflowDepth: 4,
        components: ['token' => null],
        status: 'completed',
    );

    $restored = ExecutionState::fromArray($state->toArray());

    expect($restored->toArray())->toBe($state->toArray())
        ->and($restored)->toEqual($state);
});

it('keeps the persisted key set and its order stable', function (): void {
    $state = ExecutionState::start('exec_1', 'definition_1', 'workflow_1');

    expect(array_keys($state->toArray()))->toBe([
        'executionId', 'definitionId', 'workflowId', 'currentStepId', 'inputs',
        'stepAttempts', 'stepResults', 'dependencies', 'outputs', 'errors',
        'stepsSpent', 'maxSteps', 'workflowCallStack', 'maxWorkflowDepth',
        'components', 'status', 'workflows',
        'skippedSteps',
    ]);
});

it('owns workflow outputs across canonical state, context, and persistence conversions', function (): void {
    $state = ExecutionState::start('exec_w', 'def', 'wf', ['seed' => false])
        ->withWorkflowData('base', ['outputs' => ['x' => 1, 'nil' => null]])
        ->withWorkflowData('base', ['inputs' => ['q' => 'v']])
        ->spendStep();

    $workflows = [
        'base' => [
            'inputs' => ['q' => 'v'],
            'outputs' => ['x' => 1, 'nil' => null],
        ],
    ];

    $context = $state->toContext();
    expect($context->getWorkflows())->toBe($workflows)
        ->and($context->getStepsSpent())->toBe(1);

    $fromContext = ExecutionState::fromContext($context, maxSteps: 9, maxWorkflowDepth: 3);
    expect($fromContext->toContext()->getWorkflows())->toBe($workflows)
        ->and($fromContext->stepsSpent)->toBe(1)
        ->and($fromContext->maxSteps)->toBe(9);

    $persisted = $state->toArray();
    expect($persisted['workflows'])->toBe($workflows)
        ->and(ExecutionState::fromArray($persisted)->toContext()->getWorkflows())->toBe($workflows);
});

it('round-trips workflow data through the context persistence payload', function (): void {
    $workflows = ['base' => ['inputs' => ['q' => 'v'], 'outputs' => ['x' => null, 'zero' => 0]]];
    $context = (new WorkflowContext('def', ['in' => 1], [], [], 'wf', 'exec_p'))
        ->withWorkflowData('base', $workflows['base'])
        ->withBudget(5, ['wf', 'child']);

    $persisted = $context->toArray();
    expect($persisted['workflows'])->toBe($workflows);

    $restored = WorkflowContext::fromPersisted($persisted, 'exec_p');
    expect($restored->getWorkflows())->toBe($workflows)
        ->and($restored->getStepsSpent())->toBe(5)
        ->and($restored->getWorkflowCallStack())->toBe(['wf', 'child']);

    $incoming = (new WorkflowContext('def', ['in' => 2], [], [], 'wf', 'exec_new'))
        ->withWorkflowData('base', ['outputs' => ['stale' => true]])
        ->withWorkflowData('other', ['outputs' => ['kept' => 1]]);

    expect(WorkflowContext::reconciled($incoming, $persisted, 'exec_p')->getWorkflows())->toBe([
        'base' => $workflows['base'],
        'other' => ['inputs' => [], 'outputs' => ['kept' => 1]],
    ]);
});

it('falls back to defaults instead of throwing on a corrupt persisted payload', function (): void {
    $state = ExecutionState::fromArray([
        'executionId' => 42,
        'definitionId' => null,
        'workflowId' => ['not', 'a', 'string'],
        'currentStepId' => 7,
        'inputs' => 'not-an-array',
        'stepAttempts' => null,
        'stepResults' => false,
        'dependencies' => 'nope',
        'outputs' => null,
        'errors' => 'nope',
        'stepsSpent' => '3',
        'maxSteps' => 4.5,
        'workflowCallStack' => 'workflow_1',
        'maxWorkflowDepth' => null,
        'components' => 'nope',
        'status' => 99,
        'workflows' => 'nope',
    ]);

    expect($state->executionId)->toBe('')
        ->and($state->toContext()->getWorkflows())->toBe([])
        ->and($state->definitionId)->toBe('')
        ->and($state->workflowId)->toBe('')
        ->and($state->currentStepId)->toBeNull()
        ->and($state->inputs)->toBe([])
        ->and($state->stepAttempts)->toBe([])
        ->and($state->stepResults)->toBe([])
        ->and($state->dependencies)->toBe([])
        ->and($state->outputs)->toBe([])
        ->and($state->errors)->toBe([])
        ->and($state->stepsSpent)->toBe(0)
        ->and($state->maxSteps)->toBe(1000)
        ->and($state->workflowCallStack)->toBe([''])
        ->and($state->maxWorkflowDepth)->toBe(32)
        ->and($state->components)->toBe([])
        ->and($state->status)->toBe('running');
});

it('survives corrupt step records in the workflow context', function (): void {
    $context = new WorkflowContext('definition_1', steps: ['step_1' => 'corrupt']);

    expect($context->getStepAttempts('step_1'))->toBe(0)
        ->and($context->getStepStatus('step_1'))->toBeNull()
        ->and($context->getStepAttempts('missing'))->toBe(0)
        ->and($context->getStepStatus('missing'))->toBeNull();

    $updated = $context
        ->withStepOutput('step_1', 'answer', 42)
        ->withStepStatus('step_1', StepStatus::Succeeded)
        ->withStepAttemptIncremented('step_1')
        ->withStepRequest('step_1', ['method' => 'GET'])
        ->withStepResponse('step_1', ['status' => 200]);

    expect($updated->getSteps()['step_1'])->toBe([
        'outputs' => ['answer' => 42],
        'status' => StepStatus::Succeeded,
        'attempts' => 1,
        'request' => ['method' => 'GET'],
        'response' => ['status' => 200],
    ])
        ->and($updated->getStepAttempts('step_1'))->toBe(1)
        ->and($updated->getStepStatus('step_1'))->toBe(StepStatus::Succeeded);
});

it('hydrates persisted payloads without budget keys cleanly', function (): void {
    $hydrated = WorkflowContext::fromPersisted([
        'definitionId' => 'def_1',
        'workflowId' => 'wf_1',
        'inputs' => ['a' => 1],
    ], 'exec_no_budget');

    expect($hydrated->getExecutionId())->toBe('exec_no_budget')
        ->and($hydrated->getStepsSpent())->toBe(0)
        ->and($hydrated->getWorkflowCallStack())->toBe([]);
});

it('queries step records, responses, requests, headers, and inputs directly on WorkflowContext and ExecutionState', function (): void {
    $context = (new WorkflowContext('def_1', inputs: ['token' => 'secret_xyz']))
        ->withStepResult('s1', [
            'outputs' => ['id' => 123, 'name' => 'Alice'],
            'request' => ['method' => 'POST', 'body' => ['user' => 'Alice']],
            'response' => [
                'statusCode' => 201,
                'headers' => ['Content-Type' => 'application/json', 'X-Trace-Id' => 'tr_123'],
                'body' => ['status' => 'created'],
            ],
            'inputs' => ['in_param' => 'val'],
            'statusCode' => 201,
        ]);

    expect($context->hasExecutedSteps())->toBeTrue()
        ->and($context->hasStep('s1'))->toBeTrue()
        ->and($context->hasStep('missing'))->toBeFalse()
        ->and($context->hasInput('token'))->toBeTrue()
        ->and($context->hasInput('other'))->toBeFalse()
        ->and($context->getInput('token'))->toBe('secret_xyz')
        ->and($context->getInput('other'))->toBeNull()
        ->and($context->getStepRecord('s1'))->toBeArray()
        ->and($context->getStepRecord('missing'))->toBeNull()
        ->and($context->getStepOutputs('s1'))->toBe(['id' => 123, 'name' => 'Alice'])
        ->and($context->getStepOutputs('missing'))->toBe([])
        ->and($context->getStepOutput('s1', 'id'))->toBe(123)
        ->and($context->getStepOutput('s1', 'missing'))->toBeNull()
        ->and($context->getStepOutput('missing', 'id'))->toBeNull()
        ->and($context->getStepResponse('s1'))->toHaveKey('body')
        ->and($context->getStepResponse('missing'))->toBeNull()
        ->and($context->getStepResponseBody('s1'))->toBe(['status' => 'created'])
        ->and($context->getStepResponseBody('missing'))->toBeNull()
        ->and($context->getStepResponseHeader('s1', 'content-type'))->toBe('application/json')
        ->and($context->getStepResponseHeader('s1', 'X-Trace-Id'))->toBe('tr_123')
        ->and($context->getStepResponseHeader('s1', 'nonexistent'))->toBeNull()
        ->and($context->getStepResponseHeader('missing', 'any'))->toBeNull()
        ->and($context->getStepRequest('s1'))->toHaveKey('method')
        ->and($context->getStepRequest('missing'))->toBeNull()
        ->and($context->getStepInputs('s1'))->toBe(['in_param' => 'val'])
        ->and($context->getStepInputs('missing'))->toBe([])
        ->and($context->getStepStatusCode('s1'))->toBe(201)
        ->and($context->getStepStatusCode('missing'))->toBeNull();

    $emptyCtx = new WorkflowContext('def_empty');
    expect($emptyCtx->hasExecutedSteps())->toBeFalse();

    $state = ExecutionState::start('exec_1', 'def_1', 'wf_1')
        ->withStepResult('s1', ['outputs' => ['x' => 1]])
        ->withError('some error');

    expect($state->hasStepResult('s1'))->toBeTrue()
        ->and($state->hasStepResult('missing'))->toBeFalse()
        ->and($state->stepResult('s1'))->toBe(['outputs' => ['x' => 1]])
        ->and($state->stepResult('missing'))->toBeNull()
        ->and($state->stepOutputs('s1'))->toBe(['x' => 1])
        ->and($state->stepOutputs('missing'))->toBe([])
        ->and($state->hasErrors())->toBeTrue()
        ->and($state->isRunning())->toBeTrue()
        ->and($state->isSucceeded())->toBeFalse()
        ->and($state->isFailed())->toBeFalse();

    $succeededState = $state->withStatus('succeeded');
    expect($succeededState->isSucceeded())->toBeTrue()
        ->and($succeededState->isRunning())->toBeFalse();

    $failedState = $state->withStatus('failed');
    expect($failedState->isFailed())->toBeTrue()
        ->and($failedState->isRunning())->toBeFalse();
});
