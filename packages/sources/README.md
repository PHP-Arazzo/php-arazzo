# php-arazzo/arazzo-sources

Arazzo source resolution, fetching, and zero-vendor OpenAPI normalization.

[![PHP](https://img.shields.io/badge/PHP-^8.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](../../LICENSE.md)

`arazzo-sources` is responsible for fetching and indexing the external API source descriptions (such as OpenAPI 3.0 and 3.1 documents) that an Arazzo document references in `sourceDescriptions`.

---

## Key Highlights

- **Zero Vendor Purity**: Operates strictly on decoded associative arrays. No `cebe/php-openapi` AST dependencies.
- **Protocol Agnostic**: Normalizes external specifications into canonical, typed collections (`ResolvedOperationCollection`).
- **Extensible Fetching & Normalization**: Built-in support for `http`, `https`, and `file` schemes, with pluggable fetchers and normalizers.
- **Enterprise DI Ready**: Clean public interface `SourcesInterface`, implementing `SourceResolverInterface`.

---

## Installation

```bash
composer require php-arazzo/arazzo-sources
```

For HTTP fetching, ensure a PSR-18 client and PSR-17 factories are installed (e.g. `guzzlehttp/guzzle`).

---

## Quickstart

Use the fluent static factory `Sources::create()`:

```php
use Alama\Arazzo\Sources\Sources;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

// 1. Create the Sources facade with your HTTP client
$sources = Sources::create()
    ->withHttpClient(new Client(), new HttpFactory());

// 2. Resolve an individual source description
$sourceDoc = $sources->resolve($sourceDescription, basePath: getcwd());
// $sourceDoc->content contains the decoded specification array

// 3. Normalize all sources in an Arazzo document into a typed collection
$operations = $sources->normalizeSources($arazzoDocument);
// Returns ResolvedOperationCollection

// 4. Check whether an operation exists
if ($operations->has('api', 'getUser')) {
    $op = $operations->get('api', 'getUser');
    echo "Path: {$op->path}, Method: {$op->method}\n";
}
```

---

## Features & Usage

### 1. Zero-Vendor OpenAPI Normalization
`arazzo-sources` normalizes OpenAPI 3.0.x and 3.1.x specifications directly into canonical DTOs:
- Resolves parameter inheritance between path-items and operations.
- Resolves server URL cascades (root -> pathItem -> operation).
- Emits typed `NormalizedOpenApiOperation` and `ResolvedOperation` instances.

### 2. Extracting Known Operations for Validation
Before running a workflow, pass known operations to `Document::validate()` to verify that every step's `operationId` or `operationPath` exists in the referenced API:

```php
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Sources\Sources;

$document = Document::create()->load('workflow.arazzo.yaml');
$sources = Sources::create();

// Resolves and indexes operations across all declared source descriptions
$knownOperations = $sources->knownOperations($document);

// Validates document and confirms step targets resolve against real sources
$validation = Document::create()->validate($document, $knownOperations);
```

### 3. Registering Custom Fetchers
Need to fetch OpenAPI documents from AWS S3, a private registry, or an internal vault? Register a custom fetcher implementing `SourceFetcherInterface`:

```php
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Sources\Sources;

final class S3Fetcher implements SourceFetcherInterface
{
    public function fetch(string $url): string
    {
        // Fetch document content from s3://...
        return $s3Client->getObject(...);
    }
}

$sources = Sources::create()
    ->withFetcher('s3', new S3Fetcher());
```

### 4. Registering Custom Normalizers
Need to normalize non-standard formats into Arazzo? Register a normalizer implementing `SourceNormalizerInterface` either per-instance via `withNormalizer()` or globally via `SourceNormalizerRegistry::create()` (with optional `#[AsSourceNormalizerPlugin]` metadata):

```php
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\Sources;

// Per-instance override (takes precedence over global registry):
$sources = Sources::create()
    ->withNormalizer($customNormalizer);

// Or register globally for all Sources instances:
SourceNormalizerRegistry::create()->register($customNormalizer);
```

### 5. Seeding In-Memory Source Documents
For tests or offline execution, seed pre-decoded `SourceDocument` instances immutably via `withDocument()`:

```php
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Sources;

$sources = Sources::create()
    ->withDocument(new SourceDocument('api', SourceType::Openapi, 'https://api.example/openapi.json', $openApiArray));
```

### 6. Dependency Injection & Enterprise Frameworks
The `Sources` class implements `SourcesInterface` and `SourceResolverInterface`, with optional constructor parameters for seamless DI autowiring in Symfony, Laravel, Drupal, Spryker, and WordPress:

```php
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

// Symfony / Laravel service provider:
$container->singleton(SourcesInterface::class, function ($app) {
    return Sources::create()
        ->withHttpClient(
            $app->get(ClientInterface::class),
            $app->get(RequestFactoryInterface::class)
        );
});

$container->alias(SourcesInterface::class, SourceResolverInterface::class);
```
