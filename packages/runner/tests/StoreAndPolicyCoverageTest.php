<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Support\Events\Dispatcher\SimpleEventDispatcher;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\InvalidSourcePolicyException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Execution\Defaults\EvaluationOutputExtractor;
use Alama\Arazzo\Runner\Execution\Defaults\ExecutionStoreCorrelationBridge;
use Alama\Arazzo\Runner\Execution\Defaults\InMemoryLockManager;
use Alama\Arazzo\Runner\Execution\SourcePolicyResolver;
use Alama\Arazzo\Runner\Plugins\OperationExecutor\SubWorkflowStepExecutor;
use Alama\Arazzo\Runner\Policy\ExecutionBudgets;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('covers InMemoryExecutionStore state, status, and correlation operations end-to-end', function (): void {
    $fixedNow = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $clock = new class($fixedNow) implements ClockInterface
    {
        public function __construct(private readonly DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };

    $store = new InMemoryExecutionStore($clock);

    $store->saveState('e1', ['k' => 'v']);
    expect($store->loadState('e1'))->toBe(['k' => 'v']);
    $store->deleteState('e1');
    expect($store->loadState('e1'))->toBeNull();

    $store->saveCorrelation('c1', 'e1', 's1', 'chan/a', 60);
    $found = $store->findCorrelation('c1');
    expect($found)->not->toBeNull()
        ->and($found->expiresAt)->toEqual($fixedNow->modify('+60 seconds'))
        ->and($store->hasPendingCorrelations('e1'))->toBeTrue()
        ->and($store->hasPendingCorrelations('e2'))->toBeFalse();

    $store->consumeCorrelation('c1');
    expect($store->findCorrelation('c1'))->toBeNull()
        ->and($store->hasPendingCorrelations('e1'))->toBeFalse();
});

it('bridges ExecutionStoreInterface onto PendingCorrelationRegistryInterface', function (): void {
    $store = new InMemoryExecutionStore();
    $bridge = new ExecutionStoreCorrelationBridge($store);

    $bridge->create('c_bridge', 'e1', 'wait', 'channels/a', 30);
    expect($bridge->findByCorrelationId('c_bridge')?->executionId)->toBe('e1')
        ->and($bridge->existsForExecution('e1'))->toBeTrue();

    $bridge->consume('c_bridge');
    expect($bridge->findByCorrelationId('c_bridge'))->toBeNull()
        ->and($bridge->existsForExecution('e1'))->toBeFalse();
});

it('covers InMemoryLockManager tryAcquire and release', function (): void {
    $locks = new InMemoryLockManager();

    expect($locks->tryAcquire('k', 10))->toBeTrue();
    $locks->release('k');
    expect($locks->acquire('k', 10, static fn (): string => 'ok'))->toBe('ok');
});

it('extracts Selector, JSONPath, runtime Expression, and literal outputs via EvaluationOutputExtractor', function (): void {
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());
    $extractor = new EvaluationOutputExtractor($engine);

    $step = new Step(
        's1',
        null,
        new StepTarget(),
        new StepFlow(),
        new StepIo(outputs: [
            'sel' => new Selector(null, '$.response.body.id', ExpressionType::JsonPath),
            'jp' => new Expression('$.name'),
            'expr' => new Expression('$inputs.greeting'),
            'lit' => 'fixed',
        ]),
    );

    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);

    // Non-array step record and non-array response degrade cleanly to empty body.
    $bareCtx = new WorkflowContext('def', ['greeting' => 'hi'], ['s1' => 'bad']);
    expect($extractor->extractOutputs($step, $bareCtx, $doc)['lit'])->toBe('fixed');

    $noRespCtx = new WorkflowContext('def', ['greeting' => 'hi'], ['s1' => ['response' => 'bad']]);
    expect($extractor->extractOutputs($step, $noRespCtx, $doc)['expr'])->toBe('hi');

    $fullCtx = (new WorkflowContext('def', ['greeting' => 'hello']))
        ->withStepResponse('s1', ['body' => ['id' => 7, 'name' => 'Rex']]);

    $outputs = $extractor->extractOutputs($step, $fullCtx, $doc);
    expect($outputs['jp'])->toBe('Rex')
        ->and($outputs['expr'])->toBe('hello')
        ->and($outputs['lit'])->toBe('fixed');
});

it('covers ExecutionBudgets, ExecutionPolicy, and all SourcePolicyResolver rules', function (): void {
    $budgets = ExecutionBudgets::default()->withMaxSteps(50)->withMaxWorkflowDepth(4);
    expect($budgets->maxSteps)->toBe(50)
        ->and($budgets->maxWorkflowDepth)->toBe(4);

    $policy = ExecutionPolicy::default()
        ->withMaxSteps(100)
        ->withMaxWorkflowDepth(8)
        ->withStateTtlSeconds(300);
    expect($policy->maxSteps())->toBe(100)
        ->and($policy->maxWorkflowDepth())->toBe(8)
        ->and($policy->stateTtlSeconds)->toBe(300);

    $resolver = new SourcePolicyResolver();

    // Duplicate URL across two sourceDescription names is rejected fail-fast.
    $dupDoc = new ArazzoDocument(
        '1.0.0',
        new Info('T', null, null, '1'),
        [
            new SourceDescription('apiA', 'https://same.example/openapi.yaml', SourceType::Openapi),
            new SourceDescription('apiB', 'https://same.example/openapi.yaml', SourceType::Openapi),
        ],
        [],
        new Components([], [], [], []),
        [],
    );
    $dupPolicy = ExecutionPolicy::default()
        ->withSourcePolicy('apiA', SourcePolicy::default())
        ->withSourcePolicy('apiB', SourcePolicy::default());
    expect(fn () => $resolver->validate($dupDoc, $dupPolicy))->toThrow(InvalidSourcePolicyException::class);

    // Resolution via $sourceDescriptions.<name>.* operationPath, qualified rpcMethod, single-source fallback, and sub-workflow null.
    $singleDoc = new ArazzoDocument(
        '1.0.0',
        new Info('T', null, null, '1'),
        [new SourceDescription('petstore', 'https://pet.example/openapi.yaml', SourceType::Openapi)],
        [],
        new Components([], [], [], []),
        [],
    );
    $petPolicy = SourcePolicy::default()->withServer('http://pet.local');
    $execPolicy = ExecutionPolicy::default()->withSourcePolicy('petstore', $petPolicy);

    $pathStep = StepFactory::http('s_path', null, new StepFlow(), new StepIo(), operationPath: '$sourceDescriptions.petstore.paths./pets.get');
    $wsdlStep = new Step('s_wsdl', null, StepTarget::wsdl('petstore.listPets'), new StepFlow(), new StepIo());
    $unqualifiedStep = StepFactory::http('s_unqual', null, new StepFlow(), new StepIo(), operationId: 'getPet');
    $subWfStep = new Step('s_sub', null, StepTarget::workflow('child'), new StepFlow(), new StepIo());

    expect($resolver->resolveForStep($pathStep, $singleDoc, $execPolicy))->toBe($petPolicy)
        ->and($resolver->resolveForStep($wsdlStep, $singleDoc, $execPolicy))->toBe($petPolicy)
        ->and($resolver->resolveForStep($unqualifiedStep, $singleDoc, $execPolicy))->toBe($petPolicy)
        ->and($resolver->resolveForStep($subWfStep, $singleDoc, $execPolicy))->toBeNull();
});

it('covers Runner::preflight() with workflowId inputs schema validation', function (): void {
    $doc = new ArazzoDocument(
        '1.0.0',
        new Info('T', null, null, '1'),
        [],
        [
            new Workflow(
                'wf',
                null,
                null,
                [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 10],
                    ],
                ],
                [],
                [StepFactory::http('s', null, new StepFlow(), new StepIo(), 'op')],
                [],
                [],
                [],
                [],
            ),
        ],
        new Components([], [], [], []),
        [],
    );

    $ok = Runner::create()->preflight($doc, 'wf', ['name' => 'alice']);
    expect($ok->isValid())->toBeTrue()
        ->and($ok->inputs['limit'])->toBe(10);

    $bad = Runner::create()->preflight($doc, 'wf', []);
    expect($bad->isValid())->toBeFalse();
});

it('covers all AsyncRunner builders, preflight failure, missing-state resume, and resumeCorrelation', function (): void {
    $step = new Step('wait', null, StepTarget::async('receive', 'channels/orders'), new StepFlow(), new StepIo());
    $workflow = new Workflow(
        'wf',
        null,
        null,
        [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'tag' => ['type' => 'string', 'default' => 'v1'],
            ],
        ],
        [],
        [$step],
        [],
        [],
        [],
        [],
    );
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$workflow], new Components([], [], [], []), []);

    $store = new InMemoryExecutionStore();
    $definitions = new InMemoryDefinitionStore();
    $defId = $definitions->save($doc, 'pinned-v1');
    $queue = new SyncQueueDriver();
    $engine = new EvaluationEngine(expression: ExpressionEngine::create());

    $resolver = Mockery::mock(OperationResolverInterface::class);
    $resolver->shouldReceive('supportsStep')->andReturn(false);

    $async = AsyncRunner::create()
        ->withStore($store)
        ->withDefinitions($definitions)
        ->withQueueDriver($queue)
        ->withLockManager(new InMemoryLockManager())
        ->withEventDispatcher(new SimpleEventDispatcher())
        ->withLogger(new NullLogger())
        ->withTracer(new NullTracer())
        ->withClock(new SystemClock())
        ->withOutputExtractor(new EvaluationOutputExtractor($engine))
        ->withExecutor(new class() implements OperationExecutorPluginInterface
        {
            public function name(): string
            {
                return 'noop';
            }

            public function priority(): int
            {
                return 0;
            }

            public function supports(Step $step, ArazzoDocument $document): bool
            {
                return false;
            }

            public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
            {
                return StepExecutionOutcome::resolved(200, [], []);
            }
        });

    // Missing state on resume throws ExecutionError.
    expect(fn () => $async->resume($doc, 'missing-exec'))->toThrow(ExecutionError::class);

    // Preflight failure on invalid inputs and on unresolved operation resolver.
    expect(fn () => $async->withOperationResolver($resolver)->start($doc, 'wf', []))->toThrow(PreflightFailureException::class);

    // Preflight disabled skips schema validation; explicit pre-saved definitionId is reused.
    $execId = $async->withPolicy(ExecutionPolicy::default()->withPreflight(false))
        ->start($doc, 'wf', [], executionId: 'e_preflight_off', definitionId: $defId);
    expect($execId)->toBe('e_preflight_off');

    // Valid inputs populate defaults through preflight.
    $validExec = $async->start($doc, 'wf', ['id' => 42], executionId: 'e_valid', definitionId: $defId);
    expect($validExec)->toBe('e_valid');

    // resumeCorrelation completes a suspended execution end-to-end.
    $store->saveState('e_corr', [
        'definitionId' => $defId,
        'workflowId' => 'wf',
        'steps' => [],
        'inputs' => [],
        'components' => [],
    ]);
    $store->saveCorrelation('corr_ok', 'e_corr', 'wait', 'channels/orders');

    $async->resumeCorrelation('corr_ok', ['statusCode' => 200, 'body' => ['done' => true]]);

    expect($store->statusOf('e_corr'))->toBe(ExecutionStatus::Succeeded);
});

it('exposes SubWorkflowStepExecutor metadata', function (): void {
    $sub = new SubWorkflowStepExecutor(
        Mockery::mock(WorkflowExecutor::class),
        new EvaluationEngine(expression: ExpressionEngine::create()),
    );

    expect($sub->name())->toBe('subworkflow')
        ->and($sub->priority())->toBe(0);
});
