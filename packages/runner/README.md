# php-arazzo/arazzo-runner

[![PHP Version](https://img.shields.io/badge/php-%5E8.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](../../LICENSE.md)

The protocol-agnostic, vendor-agnostic Arazzo 1.0.x / 1.1.0 workflow execution engine: synchronous in-process runs, durable queue-driven execution, `ExecutionPolicy` & per-source `SourcePolicy`, preflight validation, retry/goto/sub-workflow semantics, PSR-14 lifecycle events, PSR-3 logging, PSR-20 clocks, and vendor-agnostic tracing.

## Installation

```bash
composer require php-arazzo/arazzo-runner
composer require php-arazzo/arazzo-protocol-http
```

## Quickstart

### Synchronous Execution (`Runner`)

The synchronous entry point is [`Runner`](src/Runner.php) (implementing [`RunnerInterface`](src/RunnerInterface.php)). Wire a protocol executor plugin (such as `HttpProtocol` from `php-arazzo/arazzo-protocol-http`) and call `run()`:

```php
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Runner\Runner;

$document = Document::create()->load('workflow.arazzo.yaml');
$http = HttpProtocol::create();

$runner = Runner::create()
    ->withExecutor($http->executor())
    ->withOperationResolver($http->resolver());

$result = $runner->run($document, 'loginAndFetchPets', ['username' => 'alice']);

if ($result->isSucceeded()) {
    var_dump($result->outputs);
}
```

`$result` is a typed [`ExecutionResult`](src/Data/ExecutionResult.php) carrying `$status` (`ExecutionStatus`), `$outputs`, per-step [`StepResult`](src/Data/StepResult.php) verdicts, `$stepsSpent`, and `$workflowCallStack`.

## Features & Usage

### 1. `ExecutionPolicy`, Per-Source `SourcePolicy` & Preflight

All execution budgets, retry limits, preflight toggles, state TTLs, and per-API policies are configured via immutable [`ExecutionPolicy`](src/Policy/ExecutionPolicy.php) (set as default via `withPolicy()` or overridden per `run()` call):

```php
use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runtime\Policy\RetryPolicy;

$policy = ExecutionPolicy::default()
    ->withMaxSteps(500)
    ->withMaxWorkflowDepth(16)
    ->withRetryPolicy(new RetryPolicy(maxAttempts: 5, backoffMultiplier: 2.0))
    ->withSourcePolicy(
        'petstore',
        SourcePolicy::default()
            ->withServer('http://localhost:8080/api/v3')
            ->withCredential('api_key', Credential::apiKey($petstoreKey)),
    )
    ->withSourcePolicy(
        'payments',
        SourcePolicy::default()
            ->withServer('https://{region}.pay.example')
            ->withServerVariables(['region' => 'eu'])
            ->withCredential('bearer', Credential::bearer($paymentsToken)),
    );

$result = $runner->run($document, 'buyPet', ['petId' => 42], $policy);
```

Source policies are validated fail-fast before step 1 (`InvalidSourcePolicyException` on unknown, dotted, or `type: arazzo` source keys) and attached transiently only to steps targeting that source description — never serialized into persisted state and never leaked across APIs.

Validate inputs and operation resolution without running any steps via `preflight()`:

```php
$preflight = $runner->preflight($document, 'buyPet', ['petId' => 42]);
```

### 2. Durable / Queue-Driven Execution (`AsyncRunner`)

Framework hosts (such as Laravel) and resumable CLI runners use [`AsyncRunner`](src/AsyncRunner.php) (implementing [`AsyncRunnerInterface`](src/AsyncRunnerInterface.php)) with two clean persistence ports (`ExecutionStoreInterface` + `DefinitionStoreInterface`) and orthogonal queue/lock drivers:

```php
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;

$queue = new SyncQueueDriver();

$async = AsyncRunner::create()
    ->withStore($executionStore)
    ->withDefinitions($definitionStore)
    ->withQueueDriver($queue)
    ->withLockManager($lockManager)
    ->withExecutor($http->executor());

// Enqueue the first runnable step:
$executionId = $async->start($document, 'buyPet', ['petId' => 42]);

// In your queue worker:
$async->handleStep($job);

// When an async webhook/correlation arrives:
$async->resumeCorrelation($correlationId, ['statusCode' => 200, 'body' => $payload]);
```

`AsyncRunner::create()` ships with public in-memory defaults ([`InMemoryExecutionStore`](src/Store/InMemoryExecutionStore.php), [`InMemoryDefinitionStore`](src/Store/InMemoryDefinitionStore.php), [`SyncQueueDriver`](src/Queue/SyncQueueDriver.php)) for local runs and tests.

### 3. Observability & Determinism

Both `Runner` and `AsyncRunner` share identical observability and determinism seams:

```php
use Alama\Arazzo\Runtime\Telemetry\OtelTracer;

$runner = Runner::create()
    ->withExecutor($http->executor())
    ->withEventDispatcher($psr14Dispatcher)
    ->withLogger($psr3Logger)
    ->withTracer(new OtelTracer()) // optional OpenTelemetry adapter from arazzo-engine
    ->withClock($psr20Clock);
```

Lifecycle events under `Alama\Arazzo\Runner\Events\` (emitted via PSR-14):
- `RunStartedEvent`, `RunCompletedEvent`, `RunFailedEvent`
- `StepStartedEvent`, `StepExecutedEvent`, `StepRetriedEvent`, `StepFailedEvent`
- `CorrelationPendingEvent`, `CorrelationResumedEvent`

### 4. Global vs. Per-Instance Protocol Executors

Framework service providers can register DI-resolved `OperationExecutorPluginInterface` plugins once globally via [`OperationExecutorRegistry`](src/Registries/OperationExecutorRegistry.php):

```php
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;

OperationExecutorRegistry::create()->register($httpExecutor);
```

Per-instance `withExecutor()` calls layer on top of the global registry without mutating it. `SubWorkflowStepExecutor` is automatically wired per execution graph.

### 5. Exceptions

All runner exceptions live under `Alama\Arazzo\Runner\Exceptions\` and extend [`RunnerException`](src/Exceptions/RunnerException.php):

- `InvalidSourcePolicyException`
- `PreflightFailureException` (carries `ValidationResult $result`)
- `UnknownWorkflowException`
- `ExecutionError`
- `GotoTargetNotFoundException`
- `StepBudgetExceededException`
- `WorkflowCycleException`
- `WorkflowDepthExceededException`

### 6. Dependency Injection

Both `Runner` and `AsyncRunner` expose autowirable public constructors for DI containers:

```php
$container->singleton(RunnerInterface::class, Runner::class);
$container->singleton(AsyncRunnerInterface::class, AsyncRunner::class);
```

## Architecture Guarantees

Enforced by Pest architecture tests ([`tests/ArchTest.php`](tests/ArchTest.php)):

- **Protocol-agnostic**: Zero imports of `Alama\Arazzo\Protocol\*` or `Alama\Arazzo\Sources\*`.
- **Transport-, vendor-, & framework-free**: Zero imports of `GuzzleHttp`, `Psr\Http`, `cebe\openapi`, `OpenTelemetry`, or `Illuminate`.
- **Queue-free sync core**: `Sync\` never references `Async\` or `QueueDriverInterface`.
- **Immutable public surface**: `Runner`, `AsyncRunner`, `ExecutionPolicy`, `ExecutionBudgets`, `ExecutionResult`, `StepResult`, `ExecuteStepJob`, and all event DTOs are `final readonly`.

See [`docs/architecture/07-runner-package-map.md`](../../docs/architecture/07-runner-package-map.md), [`ADR-009`](../../docs/adr/ADR-009-runner-package-public-interface.md), and [`ADR-010`](../../docs/adr/ADR-010-monorepo-observability-and-policy-standards.md).
