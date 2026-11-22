# Sources Package Redesign & Public Interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor `alama/arazzo-sources` to establish a clean, encapsulated public interface (`SourcesInterface` & `Sources`), eliminate leaky bags (`SourceRuntime`, `SourceGraph`), remove third-party vendor dependencies (`cebe/php-openapi`), promote shared SPIs and DTOs to `contracts`, and align enterprise DI across `sources` and `document`.

**Architecture:** A facade-and-SPI pattern where `Sources` acts as the single entry point to source resolution and pure-array OpenAPI normalization, delegating runtime operation execution to protocol packages (`protocol-http`, `protocol-soap`, `protocol-rpc`). Shared contracts (`SourceFetcherInterface`, `SourceResolverInterface`, `ResolvedOperationCollection`) reside in `packages/contracts` to decouple packages under the Balanced Coupling model.

**Tech Stack:** PHP 8.4, Pest PHP 5, Composer, PSR-7, PSR-17, PSR-18, PSR-16.

---

### Task 1: Add `SourceFetcherInterface` and `SourceResolverInterface` to `packages/contracts`

**Files:**
- Create: `packages/contracts/src/Interfaces/SourceFetcherInterface.php`
- Create: `packages/contracts/src/Interfaces/SourceResolverInterface.php`
- Test: `packages/contracts/tests/Contracts/Interfaces/SourceInterfacesTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/contracts/tests/Contracts/Interfaces/SourceInterfacesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

it('defines the SourceFetcherInterface SPI contract', function (): void {
    $fetcher = new class() implements SourceFetcherInterface {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return "fetched: {$urlOrPath} from {$basePath}";
        }
    };

    expect($fetcher->fetch('spec.yaml', '/base'))->toBe('fetched: spec.yaml from /base');
});

it('defines the SourceResolverInterface SPI contract', function (): void {
    $resolver = new class() implements SourceResolverInterface {
        public function resolve(SourceDescription $source, string $basePath): SourceDocument
        {
            return new SourceDocument(
                name: $source->name,
                type: $source->type,
                canonicalUri: "file://{$basePath}/{$source->url}",
                content: ['openapi' => '3.0.0'],
            );
        }
    };

    $source = new SourceDescription('test', 'test.yaml', SourceType::Openapi);
    $resolved = $resolver->resolve($source, '/workspace');

    expect($resolved->name)->toBe('test')
        ->and($resolved->canonicalUri)->toBe('file:///workspace/test.yaml')
        ->and($resolved->content)->toBe(['openapi' => '3.0.0']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest packages/contracts/tests/Contracts/Interfaces/SourceInterfacesTest.php`
Expected: FAIL with "Interface ... not found".

- [ ] **Step 3: Write minimal implementation**

Create `packages/contracts/src/Interfaces/SourceFetcherInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

interface SourceFetcherInterface
{
    /**
     * Fetch raw document content from a URL or filesystem path.
     */
    public function fetch(string $urlOrPath, string $basePath): string;
}
```

Create `packages/contracts/src/Interfaces/SourceResolverInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

interface SourceResolverInterface
{
    /**
     * Resolve and decode a source description into a SourceDocument.
     */
    public function resolve(SourceDescription $source, string $basePath): SourceDocument;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest packages/contracts/tests/Contracts/Interfaces/SourceInterfacesTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add packages/contracts/src/Interfaces/SourceFetcherInterface.php packages/contracts/src/Interfaces/SourceResolverInterface.php packages/contracts/tests/Contracts/Interfaces/SourceInterfacesTest.php
git commit -m "feat(contracts): add SourceFetcherInterface and SourceResolverInterface"
```

---

### Task 2: Promote Operation Models & `ResolvedOperationCollection` to `packages/contracts`

**Files:**
- Create: `packages/contracts/src/Spec/NormalizedOpenApiOperation.php`
- Create: `packages/contracts/src/Spec/ResolvedOperation.php`
- Create: `packages/contracts/src/Data/ResolvedOperationCollection.php`
- Modify: `packages/document/src/NormalizedOpenApiOperation.php` (class alias / extension for BC)
- Modify: `packages/document/src/ResolvedOperation.php` (class alias / extension for BC)
- Test: `packages/contracts/tests/Data/ResolvedOperationCollectionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/contracts/tests/Data/ResolvedOperationCollectionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Data;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;

it('manages resolved operations collection immutably', function (): void {
    $sourceA = new SourceDescription('pets', 'pets.yaml', SourceType::Openapi);
    $sourceB = new SourceDescription('orders', 'orders.yaml', SourceType::Openapi);

    $normalizedA = new NormalizedOpenApiOperation(
        path: '/pets',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: [],
        queryParameters: [],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $normalizedB = new NormalizedOpenApiOperation(
        path: '/orders',
        method: 'post',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: [],
        queryParameters: [],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $opA = new ResolvedOperation(source: $sourceA, normalized: $normalizedA, operationName: 'listPets');
    $opB = new ResolvedOperation(source: $sourceB, normalized: $normalizedB, operationName: 'createOrder');

    $collection = new ResolvedOperationCollection([
        'listPets' => $opA,
        '$sourceDescriptions.pets.listPets' => $opA,
        'createOrder' => $opB,
        '$sourceDescriptions.orders.createOrder' => $opB,
    ]);

    expect($collection)->toHaveCount(4)
        ->and($collection->isEmpty())->toBeFalse()
        ->and($collection->has('listPets'))->toBeTrue()
        ->and($collection->get('listPets'))->toBe($opA)
        ->and($collection->has('nonExistent'))->toBeFalse()
        ->and($collection->get('nonExistent'))->toBeNull();

    $petsOnly = $collection->forSource('pets');
    expect($petsOnly)->toHaveCount(2)
        ->and($petsOnly->has('createOrder'))->toBeFalse()
        ->and($petsOnly->has('listPets'))->toBeTrue();

    $known = $collection->toKnownOperations();
    expect($known->has('pets', 'listPets'))->toBeTrue()
        ->and($known->has('orders', 'createOrder'))->toBeTrue();
});

it('handles empty collection factory', function (): void {
    $empty = ResolvedOperationCollection::empty();

    expect($empty)->toHaveCount(0)
        ->and($empty->isEmpty())->toBeTrue()
        ->and($empty->all())->toBeEmpty();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest packages/contracts/tests/Data/ResolvedOperationCollectionTest.php`
Expected: FAIL with "Class NormalizedOpenApiOperation not found".

- [ ] **Step 3: Write minimal implementation in `packages/contracts`**

Create `packages/contracts/src/Spec/NormalizedOpenApiOperation.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

class NormalizedOpenApiOperation
{
    /**
     * @param  array<string, array<string, mixed>>  $pathParameters
     * @param  array<string, array<string, mixed>>  $queryParameters
     * @param  array<string, array<string, mixed>>  $headerParameters
     * @param  array<string, array<string, mixed>>  $cookieParameters
     * @param  array<string, mixed>  $requestBodies
     * @param  array<string, mixed>  $responses
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method,
        public readonly ?string $resolvedServerUrl,
        public readonly array $pathParameters,
        public readonly array $queryParameters,
        public readonly array $headerParameters,
        public readonly array $cookieParameters,
        public readonly array $requestBodies,
        public readonly array $responses,
    ) {}
}
```

Create `packages/contracts/src/Spec/ResolvedOperation.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\RpcProtocol;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

class ResolvedOperation
{
    public function __construct(
        public readonly SourceDescription $source,
        public readonly NormalizedOpenApiOperation $normalized,
        public readonly ?RpcProtocol $rpcProtocol = null,
        public readonly ?string $operationName = null,
        public readonly ?string $rpcMethod = null,
        public readonly ?GraphQlOperation $graphqlOperation = null,
        public readonly ?Interaction $interaction = null,
    ) {}

    public function sourceType(): SourceType
    {
        return $this->source->type;
    }

    public function binding(): string
    {
        if ($this->rpcProtocol !== null) {
            return $this->rpcProtocol->value;
        }

        return match ($this->source->type) {
            SourceType::Openapi => 'http',
            SourceType::Arazzo => 'arazzo',
            SourceType::Asyncapi => 'asyncapi',
            SourceType::Wsdl => 'soap',
            SourceType::Protobuf => 'protobuf',
            SourceType::Graphql => 'graphql',
        };
    }
}
```

Create `packages/contracts/src/Data/ResolvedOperationCollection.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Document\KnownOperations;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

final readonly class ResolvedOperationCollection implements IteratorAggregate, Countable
{
    /**
     * @param array<string, ResolvedOperation> $operations
     */
    public function __construct(
        private array $operations = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function get(string $reference): ?ResolvedOperation
    {
        return $this->operations[$reference] ?? null;
    }

    public function has(string $reference): bool
    {
        return isset($this->operations[$reference]);
    }

    public function forSource(string $sourceName): self
    {
        $filtered = array_filter(
            $this->operations,
            static fn (ResolvedOperation $op): bool => $op->source->name === $sourceName,
        );

        return new self($filtered);
    }

    public function toKnownOperations(): KnownOperations
    {
        $known = KnownOperations::none();
        foreach ($this->operations as $key => $operation) {
            $known = $known->withOperation($operation->source->name, $key);
        }

        return $known;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function all(): array
    {
        return $this->operations;
    }

    public function count(): int
    {
        return count($this->operations);
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * @return Traversable<string, ResolvedOperation>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->operations);
    }
}
```

Update `packages/document/src/NormalizedOpenApiOperation.php` to inherit/alias:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation as ContractNormalizedOpenApiOperation;

class NormalizedOpenApiOperation extends ContractNormalizedOpenApiOperation {}
```

Update `packages/document/src/ResolvedOperation.php` to inherit/alias:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Spec\ResolvedOperation as ContractResolvedOperation;

class ResolvedOperation extends ContractResolvedOperation {}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest packages/contracts/tests/Data/ResolvedOperationCollectionTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add packages/contracts/src/Spec/NormalizedOpenApiOperation.php packages/contracts/src/Spec/ResolvedOperation.php packages/contracts/src/Data/ResolvedOperationCollection.php packages/document/src/NormalizedOpenApiOperation.php packages/document/src/ResolvedOperation.php packages/contracts/tests/Data/ResolvedOperationCollectionTest.php
git commit -m "feat(contracts): promote ResolvedOperation and NormalizedOpenApiOperation to contracts with ResolvedOperationCollection"
```

---

### Task 3: Relocate `WsdlSourceNormalizer` to `packages/protocol-soap`

**Files:**
- Create: `packages/protocol-soap/src/Normalizer/WsdlSourceNormalizer.php`
- Modify: `packages/protocol-soap/src/Registrar/SoapRegistrar.php`
- Modify: `packages/protocol-soap/tests/Execution/SoapOperationExecutorTest.php`
- Modify: `packages/protocol-soap/tests/Execution/WsdlOperationResolverTest.php`
- Modify: `packages/protocol-soap/tests/Validation/XsdResponseValidatorTest.php`
- Test: `packages/protocol-soap/tests/Normalizer/WsdlSourceNormalizerTest.php`

- [ ] **Step 1: Write test in protocol-soap**

Create `packages/protocol-soap/tests/Normalizer/WsdlSourceNormalizerTest.php` (copied and adapted from `packages/sources/tests/Normalizer/WsdlSourceNormalizerTest.php` with updated namespace `Alama\Arazzo\Protocol\Soap\Normalizer\WsdlSourceNormalizer`).

- [ ] **Step 2: Copy implementation to `packages/protocol-soap`**

Create `packages/protocol-soap/src/Normalizer/WsdlSourceNormalizer.php` in namespace `Alama\Arazzo\Protocol\Soap\Normalizer`:
Ensure imports use `Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation` and `Alama\Arazzo\Contracts\Spec\ResolvedOperation`.

- [ ] **Step 3: Update `SoapRegistrar` and soap tests to use the new namespace**

In `packages/protocol-soap/src/Registrar/SoapRegistrar.php`:
Change:
```php
use Alama\Arazzo\Sources\Normalizer\WsdlSourceNormalizer;
```
To:
```php
use Alama\Arazzo\Protocol\Soap\Normalizer\WsdlSourceNormalizer;
```

Update any tests in `packages/protocol-soap/tests/` referencing `Alama\Arazzo\Sources\Normalizer\WsdlSourceNormalizer`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest packages/protocol-soap`
Expected: PASS (all tests passing with zero errors).

- [ ] **Step 5: Commit**

```bash
git add packages/protocol-soap/
git commit -m "refactor(protocol-soap): adopt WsdlSourceNormalizer into protocol-soap"
```

---

### Task 4: Relocate `OpenApiOperationResolver` & `OpenApiOperationHandle` to `packages/protocol-http`

**Files:**
- Create: `packages/protocol-http/src/Resolver/OpenApiOperationHandle.php`
- Create: `packages/protocol-http/src/Resolver/OpenApiOperationResolver.php`
- Create: `packages/protocol-http/src/Resolver/OpenApiDocumentLoader.php`
- Modify: `packages/protocol-http/src/Registrar/ProtocolHttpRegistrar.php`
- Test: `packages/protocol-http/tests/Resolver/OpenApiOperationResolverTest.php`

- [ ] **Step 1: Copy `OpenApiOperationHandle` and `OpenApiOperationResolver` to `packages/protocol-http`**

Create `packages/protocol-http/src/Resolver/OpenApiOperationHandle.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;

final class OpenApiOperationHandle extends ResolvedOperation
{
    public readonly ResolvedOperation $operation;

    public function __construct(
        ResolvedOperation $operation,
        public readonly OpenApi $openApi,
        public readonly Operation $cebeOperation,
    ) {
        parent::__construct(
            source: $operation->source,
            normalized: $operation->normalized,
            rpcProtocol: $operation->rpcProtocol,
            operationName: $operation->operationName,
            rpcMethod: $operation->rpcMethod,
            graphqlOperation: $operation->graphqlOperation,
            interaction: $operation->interaction,
        );
        $this->operation = $operation;
    }
}
```

Create `packages/protocol-http/src/Resolver/OpenApiDocumentLoader.php`:
Adopt the loader into `Alama\Arazzo\Protocol\Http\Resolver\OpenApiDocumentLoader`, using `Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface`.

Create `packages/protocol-http/src/Resolver/OpenApiOperationResolver.php`:
Adopt `OpenApiOperationResolver` into `Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver`, implementing `Alama\Arazzo\Document\OperationResolverInterface`.

- [ ] **Step 2: Update `ProtocolHttpRegistrar` and protocol-http internal usages**

Update `packages/protocol-http/src/Registrar/ProtocolHttpRegistrar.php` and execution classes (`DefaultOpenApiExecutor`, `StepOutputExtractor`, `ResponseSchemaValidator`) to reference `Alama\Arazzo\Protocol\Http\Resolver\*`.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/pest packages/protocol-http`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/protocol-http/
git commit -m "refactor(protocol-http): adopt OpenApiOperationResolver and OpenApiOperationHandle"
```

---

### Task 5: Update `protocol-rpc` to Consume `SourceFetcherInterface` from `contracts`

**Files:**
- Modify: `packages/protocol-rpc/src/Protocol/ProtobufOperationResolver.php`
- Test: `packages/protocol-rpc/tests/Protocol/ProtobufOperationResolverTest.php`

- [ ] **Step 1: Update import in `ProtobufOperationResolver.php`**

In `packages/protocol-rpc/src/Protocol/ProtobufOperationResolver.php`:
Change:
```php
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceFetcher;
```
To:
```php
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
```
And update constructor type hint from `SourceFetcher $fetcher` to `SourceFetcherInterface $fetcher`.

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/pest packages/protocol-rpc`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add packages/protocol-rpc/
git commit -m "refactor(protocol-rpc): use SourceFetcherInterface from contracts"
```

---

### Task 6: Pure-Array `OpenApiSourceNormalizer` & Evict `cebe/php-openapi` from `packages/sources`

**Files:**
- Modify: `packages/sources/composer.json` (remove `cebe/php-openapi`)
- Modify: `packages/sources/src/Normalizer/OpenApiSourceNormalizer.php`
- Delete: `packages/sources/src/Normalizer/OpenApiOperationHandle.php`
- Delete: `packages/sources/src/Normalizer/OpenApiOperationResolver.php`
- Delete: `packages/sources/src/Normalizer/OpenApiDocumentLoader.php`
- Delete: `packages/sources/src/Normalizer/WsdlSourceNormalizer.php`
- Delete: `packages/sources/src/Normalizer/Swagger2Normalizer.php`
- Test: `packages/sources/tests/Normalizer/OpenApiSourceNormalizerTest.php`

- [ ] **Step 1: Write pure-array `OpenApiSourceNormalizer`**

In `packages/sources/src/Normalizer/OpenApiSourceNormalizer.php`:
Remove all imports of `cebe\openapi\*`.
The normalizer decodes the raw JSON/YAML content into PHP array `$rawDocument`, checks version via `OpenApiVersionDetector`, iterates `$rawDocument['paths']`, invokes `OpenApi30Normalizer` / `OpenApi31Normalizer->normalize($rawDocument, $path, $method)`, and returns an array of `ResolvedOperation`s.

- [ ] **Step 2: Delete evicted classes from `packages/sources` and remove `cebe/php-openapi` in `packages/sources/composer.json`**

Delete:
- `packages/sources/src/Normalizer/OpenApiOperationHandle.php`
- `packages/sources/src/Normalizer/OpenApiOperationResolver.php`
- `packages/sources/src/Normalizer/OpenApiDocumentLoader.php`
- `packages/sources/src/Normalizer/WsdlSourceNormalizer.php`
- `packages/sources/src/Normalizer/Swagger2Normalizer.php`

In `packages/sources/composer.json`, remove `"cebe/php-openapi": "^1.7"`.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/pest packages/sources/tests/Normalizer/OpenApiSourceNormalizerTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/sources/
git commit -m "refactor(sources): make OpenApiSourceNormalizer pure-array and remove cebe/php-openapi"
```

---

### Task 7: Exceptions Standardization in `packages/sources`

**Files:**
- Create: `packages/sources/src/Exceptions/SourceResolutionException.php`
- Create: `packages/sources/src/Exceptions/SourceFetchException.php`
- Create: `packages/sources/src/Exceptions/SourceParseException.php`
- Create: `packages/sources/src/Exceptions/UnresolvableReferenceException.php`
- Create: `packages/sources/src/Exceptions/UnsupportedSourceVersionException.php`
- Modify: `packages/sources/src/Resolver/DefaultSourceResolver.php`
- Modify: `packages/sources/src/Resolver/SourceRegistry.php`
- Modify: `packages/sources/src/Resolver/Fetchers/LocalFetcher.php`
- Modify: `packages/sources/src/Resolver/Fetchers/HttpFetcher.php`
- Delete: `packages/sources/src/Resolver/Exceptions/*`
- Test: `packages/sources/tests/Resolver/DefaultSourceResolverTest.php`

- [ ] **Step 1: Create new exceptions hierarchy in `Alama\Arazzo\Sources\Exceptions`**

Create `packages/sources/src/Exceptions/SourceResolutionException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use RuntimeException;

class SourceResolutionException extends RuntimeException {}
```

Create `packages/sources/src/Exceptions/SourceFetchException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class SourceFetchException extends SourceResolutionException {}
```

Create `packages/sources/src/Exceptions/SourceParseException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class SourceParseException extends SourceResolutionException {}
```

Create `packages/sources/src/Exceptions/UnresolvableReferenceException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class UnresolvableReferenceException extends SourceResolutionException
{
    public function __construct(string $message, public readonly string $reference = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

Create `packages/sources/src/Exceptions/UnsupportedSourceVersionException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class UnsupportedSourceVersionException extends SourceResolutionException
{
    public static function forVersion(string $version, string $sourceName): self
    {
        return new self("Unsupported source version '{$version}' for source '{$sourceName}'.");
    }
}
```

- [ ] **Step 2: Update internal resolvers to throw the new exceptions**

Update `DefaultSourceResolver`, `SourceRegistry`, and fetchers to use `Alama\Arazzo\Sources\Exceptions\*`. Remove `packages/sources/src/Resolver/Exceptions/`.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/pest packages/sources/tests/Resolver/DefaultSourceResolverTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/sources/
git commit -m "refactor(sources): standardize exceptions under Alama\Arazzo\Sources\Exceptions"
```

---

### Task 8: Implement `SourcesInterface` and `Sources` Facade in `packages/sources`

**Files:**
- Create: `packages/sources/src/SourcesInterface.php`
- Create: `packages/sources/src/Sources.php`
- Delete: `packages/sources/src/SourceGraph.php`
- Delete: `packages/sources/src/SourceRuntime.php`
- Delete: `packages/sources/src/SourceLoader.php`
- Test: `packages/sources/tests/SourcesTest.php`

- [ ] **Step 1: Write the failing test for `Sources`**

Create `packages/sources/tests/SourcesTest.php`:
```php
<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;

it('implements SourcesInterface and creates instance via factory', function (): void {
    $sources = Sources::create();

    expect($sources)->toBeInstanceOf(SourcesInterface::class);
});

it('resolves a local source description to a SourceDocument', function (): void {
    $sources = Sources::create();
    $source = new SourceDescription('pets', __DIR__.'/fixtures/document/openapi30.yaml', SourceType::Openapi);

    $resolved = $sources->resolve($source, __DIR__.'/fixtures/document');

    expect($resolved)->toBeInstanceOf(SourceDocument::class)
        ->and($resolved->name)->toBe('pets')
        ->and($resolved->type)->toBe(SourceType::Openapi)
        ->and($resolved->content)->toHaveKey('openapi');
});

it('normalizes document sources into a ResolvedOperationCollection', function (): void {
    $document = Document::create()->loadFromJson(json_encode([
        'arazzo' => '1.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'sourceDescriptions' => [
            ['name' => 'pets', 'url' => __DIR__.'/fixtures/document/openapi30.yaml', 'type' => 'openapi'],
        ],
        'workflows' => [],
    ]));

    $sources = Sources::create();
    $ops = $sources->normalizeSources($document, __DIR__.'/fixtures/document');

    expect($ops)->toBeInstanceOf(ResolvedOperationCollection::class)
        ->and($ops->has('listPets'))->toBeTrue()
        ->and($ops->get('listPets')->normalized->path)->toBe('/pets');
});

it('derives KnownOperations from document sources', function (): void {
    $document = Document::create()->loadFromJson(json_encode([
        'arazzo' => '1.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'sourceDescriptions' => [
            ['name' => 'pets', 'url' => __DIR__.'/fixtures/document/openapi30.yaml', 'type' => 'openapi'],
        ],
        'workflows' => [],
    ]));

    $sources = Sources::create();
    $known = $sources->knownOperations($document, __DIR__.'/fixtures/document');

    expect($known)->toBeInstanceOf(KnownOperations::class)
        ->and($known->has('pets', 'listPets'))->toBeTrue();
});

it('supports custom fetcher via withFetcher', function (): void {
    $customFetcher = new class() implements SourceFetcherInterface {
        public function fetch(string $urlOrPath, string $basePath): string
        {
            return '{"openapi": "3.0.0", "info": {"title": "Mock", "version": "1.0"}, "paths": {}}';
        }
    };

    $sources = Sources::create()->withFetcher('mock', $customFetcher);
    $source = new SourceDescription('mocked', 'mock://test', SourceType::Openapi);

    $resolved = $sources->resolve($source);

    expect($resolved->content['info']['title'])->toBe('Mock');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest packages/sources/tests/SourcesTest.php`
Expected: FAIL with "Class Sources not found".

- [ ] **Step 3: Write `SourcesInterface` and `Sources` implementation**

Create `packages/sources/src/SourcesInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;

interface SourcesInterface
{
    /**
     * Resolve and fetch a source description into a decoded SourceDocument.
     *
     * @throws SourceResolutionException
     */
    public function resolve(SourceDescription $source, string $basePath = ''): SourceDocument;

    /**
     * Normalize all described sources in the Arazzo document into a typed operation collection.
     */
    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection;

    /**
     * Extract KnownOperations for static Arazzo document validation.
     */
    public function knownOperations(ArazzoDocument $document, string $basePath = ''): KnownOperations;
}
```

Create `packages/sources/src/Sources.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerRegistryInterface;
use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiSourceNormalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\CachedFetcher;
use Alama\Arazzo\Sources\Resolver\Fetchers\HttpFetcher;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
use Alama\Arazzo\Sources\Resolver\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\Resolver\SourceRegistry;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class Sources implements SourcesInterface
{
    private SourceResolverInterface $resolver;
    private SourceNormalizerRegistryInterface $normalizers;
    private ?DecodePolicy $decodePolicy;

    public function __construct(
        ?SourceResolverInterface $resolver = null,
        ?SourceNormalizerRegistryInterface $normalizers = null,
        ?DecodePolicy $decodePolicy = null,
    ) {
        $this->decodePolicy = $decodePolicy;
        $this->resolver = $resolver ?? new SourceRegistry(DefaultSourceResolver::withDefaults($decodePolicy));

        if ($normalizers !== null) {
            $this->normalizers = $normalizers;
        } else {
            $registry = new SourceNormalizerRegistry();
            $registry->register(new OpenApiSourceNormalizer(
                versionDetector: new OpenApiVersionDetector(),
                normalizer30: new OpenApi30Normalizer(),
                normalizer31: new OpenApi31Normalizer(),
            ));
            $this->normalizers = $registry;
        }
    }

    public static function create(): self
    {
        return new self();
    }

    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $factory = null): self
    {
        $httpFactory = $factory ?? new HttpFactory();
        $resolver = new SourceRegistry(DefaultSourceResolver::withFetchers([
            'http' => new HttpFetcher($client, $httpFactory),
            'https' => new HttpFetcher($client, $httpFactory),
            'file' => new LocalFetcher(),
        ], $this->decodePolicy));

        return new self($resolver, $this->normalizers, $this->decodePolicy);
    }

    public function withCache(CacheInterface $cache, int $ttl = 3600): self
    {
        $client = DefaultSourceResolver::defaultHttpClient();
        $factory = new HttpFactory();
        $http = new CachedFetcher(new HttpFetcher($client, $factory), $cache, $ttl);
        $file = new CachedFetcher(new LocalFetcher(), $cache, $ttl);

        $resolver = new SourceRegistry(DefaultSourceResolver::withFetchers([
            'http' => $http,
            'https' => $http,
            'file' => $file,
        ], $this->decodePolicy));

        return new self($resolver, $this->normalizers, $this->decodePolicy);
    }

    public function withFetcher(string $scheme, SourceFetcherInterface $fetcher): self
    {
        $resolver = new SourceRegistry(DefaultSourceResolver::withFetchers([
            $scheme => $fetcher,
            'file' => new LocalFetcher(),
        ], $this->decodePolicy));

        return new self($resolver, $this->normalizers, $this->decodePolicy);
    }

    public function withNormalizer(SourceNormalizerInterface $normalizer): self
    {
        if ($this->normalizers instanceof SourceNormalizerRegistry) {
            $this->normalizers->register($normalizer);
            return $this;
        }

        $registry = new SourceNormalizerRegistry();
        $registry->register($normalizer);

        return new self($this->resolver, $registry, $this->decodePolicy);
    }

    public function withDecodePolicy(DecodePolicy $policy): self
    {
        return new self($this->resolver, $this->normalizers, $policy);
    }

    public function resolve(SourceDescription $source, string $basePath = ''): SourceDocument
    {
        return $this->resolver->resolve($source, $basePath);
    }

    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection
    {
        $index = [];
        foreach ($document->sourceDescriptions as $source) {
            $normalizer = $this->normalizers->get($source->type);
            if ($normalizer === null) {
                continue;
            }

            try {
                $resolved = $this->resolver->resolve($source, $basePath);
                $rawContent = json_encode($resolved->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $normalized = $normalizer->normalize($source, $rawContent, $document);
                $index += $normalized;
            } catch (\Throwable) {
                continue;
            }
        }

        return new ResolvedOperationCollection($index);
    }

    public function knownOperations(ArazzoDocument $document, string $basePath = ''): KnownOperations
    {
        return $this->normalizeSources($document, $basePath)->toKnownOperations();
    }
}
```

- [ ] **Step 4: Delete `SourceGraph.php`, `SourceRuntime.php`, `SourceLoader.php` and run tests**

Delete old entry points:
- `packages/sources/src/SourceGraph.php`
- `packages/sources/src/SourceRuntime.php`
- `packages/sources/src/SourceLoader.php`
Delete tests that tested deleted facades:
- `packages/sources/tests/DocumentTest.php`
- `packages/sources/tests/SourceGraphTest.php`

Run: `vendor/bin/pest packages/sources/tests/SourcesTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add packages/sources/
git commit -m "feat(sources): implement SourcesInterface and Sources facade and eliminate SourceGraph and SourceRuntime"
```

---

### Task 9: Enterprise DI Alignment for `packages/document`

**Files:**
- Modify: `packages/document/src/Document.php`
- Test: `packages/document/tests/DocumentTest.php`

- [ ] **Step 1: Write test for public container autowiring in `DocumentTest.php`**

In `packages/document/tests/DocumentTest.php`, add:
```php
it('supports container constructor instantiation with optional dependencies', function (): void {
    $document = new Document();

    expect($document)->toBeInstanceOf(DocumentInterface::class);
    expect($document->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: T\n  version: '1'\nworkflows: []\n")->info->title)->toBe('T');
});
```

- [ ] **Step 2: Update `Document.php` constructor**

In `packages/document/src/Document.php`:
Change constructor from `private` to `public`:
```php
    public function __construct(
        ?ExpressionEngineInterface $expressionEngine = null,
        ?DecodePolicy $decodePolicy = null,
    ) {
        $engine = $expressionEngine ?? new ExpressionEngine();
        $this->loader = $decodePolicy !== null ? Loader::defineDecodePolicy($decodePolicy) : Loader::new();
        $this->parser = new Parser();
        $this->validator = new Validator(RuleSet::default($engine));
    }
```
Retain `public static function create(): self` which calls `new self()`.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/pest packages/document/tests/DocumentTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/document/
git commit -m "feat(document): make Document constructor public with optional autowirable dependencies"
```

---

### Task 10: Update CLI (`ValidateCommand`, `RunCommand`) and Core/Runner Consumers

**Files:**
- Modify: `packages/cli/src/Console/Command/ValidateCommand.php`
- Modify: `packages/cli/src/Console/Command/RunCommand.php`
- Modify: `packages/core/tests/Conformance/ConformanceHarness.php`
- Modify: `packages/core/tests/Validator/InputsPreValidationTest.php`
- Modify: `packages/runner/tests/RunnerTest.php`
- Modify: `packages/runner/tests/RunnerCapabilitiesTest.php`
- Modify: `packages/runner/tests/Async/AsyncExecutionGraphAssemblerTest.php`

- [ ] **Step 1: Update `ValidateCommand` to use `Sources::create()`**

In `packages/cli/src/Console/Command/ValidateCommand.php`:
Change:
```php
$loader = SourceGraph::loader();
$knownOperations = $loader->knownOperations($document, (string) dirname($file));
```
To:
```php
$sources = Sources::create();
$knownOperations = $sources->knownOperations($document, (string) dirname($file));
```

- [ ] **Step 2: Update `RunCommand` to use `Sources::create()->withHttpClient(...)`**

In `packages/cli/src/Console/Command/RunCommand.php`:
Remove `SourceGraph::runtime(registry: $registry)`.
Instead, instantiate `OpenApiOperationResolver` directly from `Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver`:
```php
$documentFacade = Document::create();
$preflightResult = $documentFacade->validateInputs($document, (string) $workflow->workflowId, $inputs);
```

- [ ] **Step 3: Update `ConformanceHarness` and tests**

In `packages/core/tests/Conformance/ConformanceHarness.php`:
Replace `SourceGraph::runtime(...)` with direct construction of `DocumentFacade::create()` and `OpenApiOperationResolver`.

- [ ] **Step 4: Run CLI and Core tests**

Run: `vendor/bin/pest packages/cli packages/core`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/cli/ packages/core/ packages/runner/
git commit -m "refactor: update CLI and tests to use Sources::create and eliminate SourceRuntime"
```

---

### Task 11: Architecture Tests & Verification for `packages/sources`

**Files:**
- Modify: `packages/sources/tests/ArchTest.php`

- [ ] **Step 1: Update `packages/sources/tests/ArchTest.php`**

```php
<?php

declare(strict_types=1);

use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;

arch('sources is vendor-free and has zero dependencies on cebe')
    ->expect('Alama\Arazzo\Sources')
    ->not->toUse('cebe');

arch('sources does not depend on runner or cli')
    ->expect('Alama\Arazzo\Sources')
    ->not->toUse('Alama\Arazzo\Runner')
    ->not->toUse('Alama\Arazzo\Cli')
    ->not->toUse('Alama\Arazzo\Protocol')
    ->not->toUse('Illuminate');

arch('Sources is final and readonly')
    ->expect(Sources::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('Sources implements SourcesInterface')
    ->expect(Sources::class)
    ->toImplement(SourcesInterface::class);
```

- [ ] **Step 2: Run Pest on all affected packages**

Run: `vendor/bin/pest packages/sources packages/contracts packages/document packages/protocol-http packages/protocol-soap packages/protocol-rpc packages/cli`
Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add packages/sources/tests/ArchTest.php
git commit -m "test(sources): enforce strict architectural invariants and vendor purity via ArchTest"
```
