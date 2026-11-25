<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Contracts\Support\Events\Dispatcher\SimpleEventDispatcher;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunStartedEvent;
use Alama\Arazzo\Runner\Exceptions\InvalidSourcePolicyException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\RunnerException;
use Alama\Arazzo\Runner\Exceptions\StepBudgetExceededException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\RunnerInterface;
use Alama\Arazzo\Runtime\Policy\RetryPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

function runnerTestDocument(?array $inputsSchema = null, array $sourceDescriptions = [], ?array $steps = null): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('t', null, null, '1'),
        sourceDescriptions: $sourceDescriptions,
        workflows: [
            new Workflow(
                workflowId: 'w',
                summary: null,
                description: null,
                inputs: $inputsSchema,
                dependsOn: [],
                steps: $steps ?? [StepFactory::http('s', null, new StepFlow(), new StepIo(), 'op')],
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

function runnerStubExecutor(int $status = 200, array $outputs = ['ok' => true]): OperationExecutorPluginInterface
{
    return new class($status, $outputs) implements OperationExecutorPluginInterface
    {
        public function __construct(
            private readonly int $status,
            private readonly array $outputs,
        ) {}

        public function name(): string
        {
            return 'stub-executor';
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
            return StepExecutionOutcome::resolved($this->status, $this->outputs, ['status' => 'ok']);
        }
    };
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('creates a protocol-agnostic Runner implementing RunnerInterface via Runner::create()', function (): void {
    $runner = Runner::create();

    expect($runner)->toBeInstanceOf(Runner::class)
        ->and($runner)->toBeInstanceOf(RunnerInterface::class);
});

it('runs a workflow via a per-instance executor without mutating the global registry', function (): void {
    $base = Runner::create();
    $configured = $base->withExecutor(runnerStubExecutor(200, ['greeting' => 'hi']));

    expect($configured)->not->toBe($base);

    $result = $configured->run(runnerTestDocument(), 'w');

    expect($result)->toBeInstanceOf(ExecutionResult::class)
        ->and($result->workflowId)->toBe('w')
        ->and($result->status)->toBe(ExecutionStatus::Succeeded)
        ->and($result->isSucceeded())->toBeTrue()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->stepResults)->toHaveKey('s')
        ->and($result->stepResults['s']->outputs)->toBe(['greeting' => 'hi'])
        ->and(OperationExecutorRegistry::create()->all())->toBe([]);
});

it('throws UnknownWorkflowException extending RunnerException on unknown workflow id', function (): void {
    Runner::create()->run(runnerTestDocument(), 'missing');
})->throws(UnknownWorkflowException::class, "Unknown workflow 'missing'.");

it('validates workflow inputs schema during preflight even when no operation resolver is registered', function (): void {
    $doc = runnerTestDocument([
        'type' => 'object',
        'required' => ['userId'],
        'properties' => ['userId' => ['type' => 'integer']],
    ]);

    try {
        Runner::create()
            ->withExecutor(runnerStubExecutor())
            ->run($doc, 'w', ['userId' => 'not-an-int']);
        $this->fail('Expected PreflightFailureException');
    } catch (PreflightFailureException $e) {
        expect($e)->toBeInstanceOf(RunnerException::class)
            ->and($e->result->errors[0]->code)->toBe('preflight.inputs_schema');
    }
});

it('skips preflight when disabled via ExecutionPolicy::default()->withPreflight(false)', function (): void {
    $doc = runnerTestDocument([
        'type' => 'object',
        'required' => ['userId'],
        'properties' => ['userId' => ['type' => 'integer']],
    ]);

    $result = Runner::create()
        ->withExecutor(runnerStubExecutor())
        ->withPolicy(ExecutionPolicy::default()->withPreflight(false))
        ->run($doc, 'w', []);

    expect($result->isSucceeded())->toBeTrue();
});

it('runs operation resolution preflight when operation resolvers are registered', function (): void {
    $resolver = Mockery::mock(OperationResolverInterface::class);
    $resolver->shouldReceive('supportsStep')->andReturn(false);

    $validation = Runner::create()
        ->withOperationResolver($resolver)
        ->preflight(runnerTestDocument());

    expect($validation->isValid())->toBeFalse()
        ->and($validation->errors[0]->code)->toBe('preflight.no_resolver');
});

it('emits public PSR-14 lifecycle events with injected PSR-20 clock timestamps, traces spans, and logs', function (): void {
    $fixedNow = new DateTimeImmutable('2026-01-01T12:00:00Z');
    $clock = new class($fixedNow) implements ClockInterface
    {
        public function __construct(private readonly DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };

    $spans = [];
    $tracer = new class($spans) implements TracerInterface
    {
        /** @param list<string> $spans */
        public function __construct(private array &$spans) {}

        public function startSpan(string $name, array $attributes = []): SpanInterface
        {
            $this->spans[] = $name;

            return new class() implements SpanInterface
            {
                public function setAttribute(string $key, string|int|float|bool $value): void {}

                public function addEvent(string $name, array $attributes = []): void {}

                public function recordException(Throwable $exception): void {}

                public function setStatus(bool $ok, ?string $description = null): void {}

                public function end(): void {}
            };
        }

        public function injectContext(array &$carrier): void {}

        public function extractContext(array $carrier): ?array
        {
            return null;
        }
    };

    $logs = [];
    $logger = new class($logs) extends AbstractLogger
    {
        /** @param list<array{level: mixed, message: string}> $logs */
        public function __construct(private array &$logs) {}

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->logs[] = ['level' => $level, 'message' => (string) $message];
        }
    };

    $dispatcher = new SimpleEventDispatcher();
    $seen = [];
    $dispatcher->subscribe(RunStartedEvent::class, function (RunStartedEvent $event) use (&$seen): void {
        $seen[] = [$event::class, $event->at];
    });
    $dispatcher->subscribe(RunCompletedEvent::class, function (RunCompletedEvent $event) use (&$seen): void {
        $seen[] = [$event::class, $event->at];
    });

    $result = Runner::create()
        ->withExecutor(runnerStubExecutor())
        ->withEventDispatcher($dispatcher)
        ->withClock($clock)
        ->withTracer($tracer)
        ->withLogger($logger)
        ->withPolicy(ExecutionPolicy::default()->withRetryPolicy(new RetryPolicy(maxAttempts: 3)))
        ->run(runnerTestDocument(), 'w');

    expect($result->isSucceeded())->toBeTrue()
        ->and($seen)->toBe([
            [RunStartedEvent::class, $fixedNow],
            [RunCompletedEvent::class, $fixedNow],
        ])
        ->and($spans)->toBe(['arazzo.workflow.run', 'arazzo.step.execute'])
        ->and($logs)->not->toBeEmpty();
});

it('applies per-call ExecutionPolicy budgets on run()', function (): void {
    $doc = runnerTestDocument(steps: [
        StepFactory::http('s1', null, new StepFlow(), new StepIo(), 'op1'),
        StepFactory::http('s2', null, new StepFlow(dependsOn: ['s1']), new StepIo(), 'op2'),
    ]);

    Runner::create()
        ->withExecutor(runnerStubExecutor())
        ->run($doc, 'w', [], ExecutionPolicy::default()->withMaxSteps(1));
})->throws(StepBudgetExceededException::class);

it('scopes SourcePolicy strictly to its addressed source description without cross-API leakage', function (): void {
    $doc = runnerTestDocument(
        sourceDescriptions: [
            new SourceDescription('petstore', 'https://petstore.example/openapi.yaml', SourceType::Openapi),
            new SourceDescription('payments', 'https://payments.example/openapi.yaml', SourceType::Openapi),
        ],
        steps: [
            StepFactory::http('s1', null, new StepFlow(), new StepIo(), 'petstore.getPet'),
            StepFactory::http('s2', null, new StepFlow(dependsOn: ['s1']), new StepIo(), 'payments.charge'),
        ],
    );

    $seenPolicies = [];
    $executor = new class($seenPolicies) implements OperationExecutorPluginInterface
    {
        /** @param array<string, ?SourcePolicy> $seen */
        public function __construct(private array &$seen) {}

        public function name(): string
        {
            return 'inspect';
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
            $this->seen[$step->stepId] = $context->getActiveSourcePolicy();

            return StepExecutionOutcome::resolved(200, [], []);
        }
    };

    $petPolicy = SourcePolicy::default()
        ->withServer('http://localhost:8080')
        ->withCredential('api_key', Credential::apiKey('PET-KEY'));

    $payPolicy = SourcePolicy::default()
        ->withServer('https://pay.internal')
        ->withCredential('api_key', Credential::apiKey('PAY-KEY'));

    Runner::create()
        ->withExecutor($executor)
        ->run(
            $doc,
            'w',
            [],
            ExecutionPolicy::default()
                ->withSourcePolicy('petstore', $petPolicy)
                ->withSourcePolicy('payments', $payPolicy),
        );

    expect($seenPolicies['s1'])->toBe($petPolicy)
        ->and($seenPolicies['s2'])->toBe($payPolicy)
        ->and($seenPolicies['s1']->credential('api_key')?->value)->toBe('PET-KEY')
        ->and($seenPolicies['s2']->credential('api_key')?->value)->toBe('PAY-KEY')
        ->and($seenPolicies['s1']->credential('api_key')?->__debugInfo()['value'])->toBe('[REDACTED]');
});

it('rejects unknown, dotted, or arazzo-type source policy keys fail-fast before any step executes', function (): void {
    $doc = runnerTestDocument(
        sourceDescriptions: [
            new SourceDescription('petstore', 'https://petstore.example/openapi.yaml', SourceType::Openapi),
            new SourceDescription('sub', 'https://sub.example/arazzo.yaml', SourceType::Arazzo),
        ],
    );

    $runner = Runner::create()->withExecutor(runnerStubExecutor());

    expect(fn () => $runner->run($doc, 'w', [], ExecutionPolicy::default()->withSourcePolicy('ghost', SourcePolicy::default())))
        ->toThrow(InvalidSourcePolicyException::class);

    expect(fn () => $runner->run($doc, 'w', [], ExecutionPolicy::default()->withSourcePolicy('sub', SourcePolicy::default())))
        ->toThrow(InvalidSourcePolicyException::class);

    expect(fn () => $runner->run($doc, 'w', [], ExecutionPolicy::default()->withSourcePolicy('sub.petstore', SourcePolicy::default())))
        ->toThrow(InvalidSourcePolicyException::class);
});
