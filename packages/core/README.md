# alama/arazzo-core

Framework-agnostic PHP engine for the [Arazzo Specification](https://github.com/OAI/Arazzo-Specification) 1.0.0/1.1.0: parser, validator, workflow executor, expression resolver, and OpenAPI source resolution.

> [!WARNING]
> **Work in progress.** This package is under active development and not yet ready for production use. APIs may change without notice before a stable `1.0.0` tag.

This package has **no framework dependency**. It depends only on PSR interfaces (`psr/log`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/simple-cache`, `psr/event-dispatcher`, `psr/container`) plus `softcreatr/jsonpath`, `cebe/php-openapi`, and `symfony/yaml`. You supply the PSR implementations (a Guzzle/Symfony HTTP client, a PSR-16 cache, etc.); the engine does the rest.

If you're on Laravel, use [`alama/laravel-arazzo`](../laravel) instead — it wires all of this together for you via a service provider. This package is for everyone else, or for anyone who wants to see (or control) exactly how the wiring works.

For a deep dive into how the pieces below fit together, see [`docs/architecture/`](../../docs/architecture) in the monorepo root, starting with [`01-system-overview.md`](../../docs/architecture/01-system-overview.md).

## Installation

```bash
composer require alama/arazzo-core
```

You'll also need a PSR-18 HTTP client and PSR-17 factories in your project — e.g.:

```bash
composer require guzzlehttp/guzzle
```

## What this package does

1. **Parses** an Arazzo YAML/JSON document into a typed, immutable object model (`Alama\Arazzo\Spec\*`).
2. **Validates** a parsed document against the spec's structural, referential, and expression rules (`ValidationRuleRegistry`, auto-discovering rules from `arazzo-document`, `arazzo-expression`, and any installed protocol packages — note that installing `arazzo-document` standalone without `arazzo-expression` runs structural/referential rules only; `arazzo-core` bundles both by default).
3. **Resolves** the external OpenAPI (and nested Arazzo) documents a workflow's `sourceDescriptions` point to (`Alama\Arazzo\Resolver\*`).
4. **Executes** a workflow: runs each step's HTTP call, evaluates `{$...}` runtime expressions against accumulated state, checks `successCriteria`, and follows `onSuccess`/`onFailure` actions (`Alama\Arazzo\Runner\*`).

## Quick start

```php
<?php

require 'vendor/autoload.php';

use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Runner\RunnerFacade;
use Alama\Arazzo\Sources\Sources;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$httpClient = new Client();
$httpFactory = new HttpFactory();

// 1. Parse and validate the Arazzo document
$documentFacade = Document::create();
$document = $documentFacade->load(__DIR__ . '/workflow.arazzo.yaml');

// 2. Wire up source resolution & operation resolver
$sources = Sources::create()->withHttpClient($httpClient, $httpFactory);
$operationResolver = OpenApiOperationResolver::create($sources);

// Optional: semantic validation checking step operations against real sources
$validation = $documentFacade->validate($document, $sources->knownOperations($document));
if (!$validation->isValid()) {
    throw new RuntimeException('Document validation failed');
}

// 3. Assemble the runner facade
$expressionEngine = new ExpressionEngine();
$evaluationEngine = new EvaluationEngine(expression: $expressionEngine);

$runner = new RunnerFacade(
    document: $documentFacade,
    operationResolver: $operationResolver,
    evaluationEngine: $evaluationEngine,
    inspector: null,
    openApiExecutor: new DefaultOpenApiExecutor($httpClient, $httpFactory),
    outputExtractor: new StepOutputExtractor($operationResolver, $evaluationEngine, $expressionEngine),
    schemaValidator: new ResponseSchemaValidator($operationResolver),
);

// 4. Execute workflow
$result = $runner->execute(
    $document,
    'myWorkflow',
    ['inputs' => ['query' => 'php']]
);

// 4. Run it.
$workflow = $document->workflows[0];
$result = $executor->execute($workflow, $document, inputs: [
    'customer_id' => 789,
]);

echo $result->status . "\n"; // 'succeeded' or 'failed'
foreach ($result->stepResults as $stepId => $stepResult) {
    echo " - {$stepId}: " . ($stepResult->success ? 'ok' : 'failed') . "\n";
}
```

This is the **synchronous, in-process** execution path — everything runs on one call stack with no queue, lock, or persistence layer. It's the right starting point for scripts, tests, and simple integrations.

For **durable, queue-driven, resumable** execution (steps run as background jobs, workflows survive process restarts, AsyncAPI steps can suspend and wait for an external correlation), you need the additional infrastructure described in [`docs/architecture/02-execution-lifecycle.md`](../../docs/architecture/02-execution-lifecycle.md) and [`06-laravel-integration.md`](../../docs/architecture/06-laravel-integration.md) — `alama/laravel-arazzo` provides all of it out of the box.

## Validating a document

```php
use Alama\Arazzo\Validator\RuleSet;
use Alama\Arazzo\Validator\Validator;

$result = (new Validator(new \Alama\Arazzo\Expression\ExpressionEngine(), RuleSet::default(new \Alama\Arazzo\Expression\ExpressionEngine())))->validate($document);

if (!$result->isValid()) {
    foreach ($result->errors as $error) {
        echo $error . "\n";
    }
}
```

## Supported OpenAPI source versions

Steps resolve operations against OpenAPI **3.0.x** and **3.1.x** sources. Swagger 2.0 sources are detected but currently rejected (`UnsupportedSourceVersionException`).

## Learn more

- [`docs/architecture/01-system-overview.md`](../../docs/architecture/01-system-overview.md) — spec-to-PHP mapping, monorepo layout, domain glossary
- [`docs/architecture/02-execution-lifecycle.md`](../../docs/architecture/02-execution-lifecycle.md) — parsing → execution → completion, in detail
- [`docs/architecture/03-dependency-graph.md`](../../docs/architecture/03-dependency-graph.md) — how `dependsOn` becomes execution order
- [`docs/architecture/04-expression-evaluation.md`](../../docs/architecture/04-expression-evaluation.md) — `{$...}` expressions, JSONPath/XPath selectors
- [`docs/architecture/05-source-resolution.md`](../../docs/architecture/05-source-resolution.md) — fetching and resolving external OpenAPI/Arazzo sources
- [`CONTEXT.md`](CONTEXT.md) — short domain glossary
- [`CHANGELOG.md`](../../CHANGELOG.md) / [`UPGRADING.md`](UPGRADING.md)

## License

MIT — see [`LICENSE.md`](../../LICENSE.md).

## Conformance

The official OAI example corpus is vendored under
`tests/Conformance/corpus/oai/` and executed (parse → validate → run against
a deterministic mock transport) as part of the test suite. See
[`docs/CONFORMANCE.md`](../../docs/CONFORMANCE.md) for the generated matrix.
