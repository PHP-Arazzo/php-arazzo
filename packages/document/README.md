# php-arazzo/arazzo-document

Arazzo document loader, parser, validator, and vendor-free domain model.

[![PHP](https://img.shields.io/badge/PHP-^8.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](../../LICENSE.md)

`arazzo-document` is a pure model package: **no network I/O, no vendor AST dependencies, and zero framework coupling**. It provides the public `Document` façade for parsing YAML and JSON Arazzo specifications, validating them against the Arazzo 1.0.0/1.1.0 specification rules, and checking workflow inputs before execution.

---

## Installation

```bash
composer require php-arazzo/arazzo-document
```

> [!IMPORTANT]
> **Standalone vs Full Validation**: `php-arazzo/arazzo-document` is intentionally decoupled from `php-arazzo/arazzo-expression` and protocol packages. Installing `arazzo-document` alone runs all structural and referential Arazzo specification rules, **not** runtime expression or JSONPath/XPath selector validation rules.
>
> - For full validation including runtime expressions (`expr.*`), selector versions, and `jsonpath` criteria (`expr.jsonpath_condition`), also install `php-arazzo/arazzo-expression` — or install the default umbrella package **`php-arazzo/arazzo-core`**, which bundles them together.
> - Protocol-specific step rules (`step.wsdl_step`, `step.rpc_step`) auto-activate whenever `php-arazzo/arazzo-protocol-soap` or `php-arazzo/arazzo-protocol-rpc` is installed.
> - All installed packages' rules are discovered automatically by `ValidationRuleRegistry` via Composer's `extra.arazzo.plugins` manifest and `#[AsValidationRule]` attributes — no manual wiring required.

---

## Quickstart

Use the fluent static factory `Document::create()`:

```php
use Alama\Arazzo\Document\Document;

$documentFacade = Document::create();

// 1. Load and parse from file (YAML or JSON)
$document = $documentFacade->load('path/to/workflow.arazzo.yaml');

// 2. Or parse directly from a string
$document = $documentFacade->parse($yamlOrJsonContent);

// 3. Validate document integrity
$validation = $documentFacade->validate($document);

if (!$validation->isValid()) {
    foreach ($validation->errors as $error) {
        echo "[{$error->rule}] {$error->message} at {$error->path}\n";
    }
}
```

---

## Features & Usage

### 1. Document Parsing & Loading
Load files or parse raw strings into immutable domain objects (`ArazzoDocument`):

```php
$document = $documentFacade->load('workflow.arazzo.yaml');

echo "Title: " . $document->info->title . "\n";
echo "Workflows count: " . count($document->workflows) . "\n";
```

### 2. Semantic & Cross-Source Validation
Validate documents against structural rules, DAG cycles, and referential integrity. When coupled with `php-arazzo/arazzo-sources`, you can perform source-integrity checks (verifying that operation paths and IDs declared in steps actually exist in the referenced OpenAPI/AsyncAPI files):

```php
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Sources\Sources;

$documentFacade = Document::create();
$sourcesFacade = Sources::create();

$document = $documentFacade->load('workflow.arazzo.yaml');

// Extract known operations from described sources (zero side effects)
$knownOperations = $sourcesFacade->knownOperations($document);

// Validate document including cross-source operation existence
$result = $documentFacade->validate($document, $knownOperations);
```

### 3. Preflight Workflow Input Validation
Validate runtime inputs against a workflow's declared JSON Schema before execution. This populates defaults and flags type or constraint violations without executing any HTTP or protocol requests:

```php
$inputResult = $documentFacade->validateInputs(
    $document,
    workflowId: 'loginAndOrder',
    inputs: ['userId' => 42]
);

if (!$inputResult->isValid()) {
    // Reject request
}

// Validated input map with schema defaults applied
$validatedInputs = $inputResult->inputs;
```

### 4. Customizing Decode Policies
Configure YAML alias depth bounds, file size limits, or parser security policies:

```php
use Alama\Arazzo\Document\Parser\DecodePolicy;

$policy = new DecodePolicy(
    maxFileSize: 10 * 1024 * 1024, // 10MB
    maxYamlAliases: 50,
);

$documentFacade = Document::create()->withDecodePolicy($policy);
```

### 5. Registering Custom Validation Rules
Register custom rules implementing `ValidationRuleInterface` either per-instance via `withRule()` or globally via `ValidationRuleRegistry::create()` (or declare them in your `composer.json` under `extra.arazzo.plugins` with `#[AsValidationRule]`):

```php
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;

// Per-instance override:
$documentFacade = Document::create()->withRule($customRule);

// Or globally for all Document instances:
ValidationRuleRegistry::create()->register($customRule);
```

### 6. Dependency Injection & Enterprise Frameworks
The `Document` class implements `DocumentInterface` and has a fully autowirable constructor with zero expression-engine coupling, making it ready for Laravel, Symfony, Drupal, Spryker, and WordPress service containers:

```php
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\Document;

// Symfony / Laravel service definition:
$container->singleton(DocumentInterface::class, fn () => Document::create());
```

---

## Architecture Guarantees
- **Pure Domain & Zero-I/O**: No HTTP requests or filesystem traversal during validation.
- **Port-and-Adapter Alignment**: Implements `DocumentInterface` for hexagonal architecture.
- **No Vendor AST**: Communicates purely through canonical contracts and typed DTOs.
