<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\Data\Enum\StepRole;
use Alama\Arazzo\Runner\Exceptions\AmbiguousStepException;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\UnknownStepException;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Runner;

function createTestSingleStepRunner(array &$executedSteps): Runner
{
    $executor = new class($executedSteps) implements OperationExecutorPluginInterface
    {
        public function __construct(private array &$executedSteps) {}

        public function name(): string
        {
            return 'single-step-test-executor';
        }

        public function priority(): int
        {
            return 0;
        }

        public function supports(Step $step, ArazzoDocument $document): bool
        {
            return true;
        }

        public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
        {
            $this->executedSteps[] = $step->stepId;

            return StepExecutionOutcome::resolved(200, ['token' => 'secret-token', 'step' => $step->stepId], ['ok' => true]);
        }
    };

    return Runner::create()->withExecutor($executor);
}

function singleStepFixtureDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('SingleStepDoc', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'checkout',
                summary: null,
                description: null,
                inputs: null,
                dependsOn: [],
                steps: [
                    StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
                    StepFactory::http('fetch-config', null, new StepFlow(dependsOn: ['login']), new StepIo(), 'configOp'),
                    StepFactory::http('create-order', null, new StepFlow(dependsOn: ['fetch-config']), new StepIo(), 'orderOp'),
                    StepFactory::http('create-shipment', null, new StepFlow(dependsOn: ['create-order']), new StepIo(), 'shipmentOp'),
                    StepFactory::http('cleanup', null, new StepFlow(), new StepIo(), 'cleanupOp'),
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
}

// S1: executes target and upstream dependencies then stops
test('it executes target and upstream dependencies then stops (S1)', function (): void {
    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $doc = singleStepFixtureDocument();

    $result = $runner->runStep($doc, 'create-order');

    expect($result->isSucceeded())->toBeTrue()
        ->and($result->executedStepIds())->toBe(['login', 'fetch-config', 'create-order'])
        ->and($result->hasStepResult('create-shipment'))->toBeFalse()
        ->and($result->hasStepResult('cleanup'))->toBeFalse();
});

// S2: executes only up to target (no step after target executed)
test('it executes only upto target and skips steps after target (S2)', function (): void {
    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $doc = singleStepFixtureDocument();

    $result = $runner->runStep($doc, 'fetch-config');

    expect($result->isSucceeded())->toBeTrue()
        ->and($result->executedStepIds())->toBe(['login', 'fetch-config'])
        ->and($result->hasStepResult('create-order'))->toBeFalse()
        ->and($result->hasStepResult('create-shipment'))->toBeFalse();
});

// S7: report distinguishes dependency steps from target
test('it distinguishes dependency steps from target in step results and toArray (S7)', function (): void {
    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $doc = singleStepFixtureDocument();

    $result = $runner->runStep($doc, 'create-order');

    $login = $result->stepResult('login');
    $config = $result->stepResult('fetch-config');
    $order = $result->stepResult('create-order');

    expect($login->isDependency())->toBeTrue()
        ->and($login->isTarget())->toBeFalse()
        ->and($login->role)->toBe(StepRole::Dependency)
        ->and($login->toArray()['dependency'])->toBeTrue()
        ->and($login->toArray()['target'])->toBeFalse()
        ->and($config->isDependency())->toBeTrue()
        ->and($order->isTarget())->toBeTrue()
        ->and($order->isDependency())->toBeFalse()
        ->and($order->role)->toBe(StepRole::Target)
        ->and($order->toArray()['target'])->toBeTrue()
        ->and($order->toArray()['dependency'])->toBeFalse()
        ->and($result->targetStepResult())->toBe($order)
        ->and($result->dependencyStepResults())->toBe([
            'login' => $login,
            'fetch-config' => $config,
        ]);
});

// R4 & S4: fails on unknown step id
test('it throws UnknownStepException on unknown step id (R4)', function (): void {
    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $doc = singleStepFixtureDocument();

    $runner->runStep($doc, 'nonexistent');
})->throws(UnknownStepException::class, "Unknown step 'nonexistent'");

// Ambiguous step handling
test('it throws AmbiguousStepException when step exists in multiple workflows and workflowId omitted', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('t', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow('wf1', null, null, null, [], [
                StepFactory::http('init', null, new StepFlow(), new StepIo(), 'init1'),
            ], [], [], [], []),
            new Workflow('wf2', null, null, null, [], [
                StepFactory::http('init', null, new StepFlow(), new StepIo(), 'init2'),
            ], [], [], [], []),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $runner->runStep($doc, 'init');
})->throws(AmbiguousStepException::class, "Step 'init' is ambiguous; it exists in multiple workflows: wf1, wf2");

// S3: no-deps fails fast with step.missing_dependency_output if referenced output missing, succeeds if supplied (R2)
test('no-deps fails fast with step.missing_dependency_output when referenced output is missing (R2/S3)', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('t', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow('wf1', null, null, null, [], [
                StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
                StepFactory::http(
                    'create-order',
                    null,
                    new StepFlow(),
                    new StepIo(parameters: [
                        new Parameter('tok', ParameterIn::Header, new Expression('{$steps.login.outputs.token}')),
                    ]),
                    'orderOp',
                ),
            ], [], [], [], []),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $policy = ExecutionPolicy::default()->withNoDeps(true);

    try {
        $runner->runStep($doc, 'create-order', policy: $policy);
        $this->fail('Expected ExecutionError');
    } catch (ExecutionError $e) {
        expect($e->codeId)->toBe('step.missing_dependency_output')
            ->and($e->getMessage())->toContain("Step 'create-order' requires output 'token' from upstream step 'login', but it is missing.")
            ->and($executed)->toBe([]);
    }
});

test('no-deps succeeds when referenced output is supplied via inputs (R2/S3)', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('t', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow('wf1', null, null, null, [], [
                StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
                StepFactory::http(
                    'create-order',
                    null,
                    new StepFlow(),
                    new StepIo(parameters: [
                        new Parameter('tok', ParameterIn::Header, new Expression('{$steps.login.outputs.token}')),
                    ]),
                    'orderOp',
                ),
            ], [], [], [], []),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $policy = ExecutionPolicy::default()->withNoDeps(true);

    $result = $runner->runStep($doc, 'create-order', inputs: ['token' => 'provided-secret'], policy: $policy);

    expect($result->isSucceeded())->toBeTrue()
        ->and($executed)->toBe(['create-order'])
        ->and($result->executedStepIds())->toBe(['create-order'])
        ->and($result->hasStepResult('login'))->toBeFalse();
});

// S6: single step execution records sequential fallback
test('runStep records sequential fallback reason (S6)', function (): void {
    $executed = [];
    $runner = createTestSingleStepRunner($executed);
    $doc = singleStepFixtureDocument();

    $result = $runner->runStep($doc, 'create-order');

    expect($result->isSucceeded())->toBeTrue()
        ->and($result->hasSequentialFallbacks())->toBeTrue()
        ->and($result->sequentialFallbacks)->toBe(['--step implies sequential execution'])
        ->and($result->toArray()['sequentialFallbacks'])->toBe(['--step implies sequential execution']);
});
