# Document Package Redesign & Public Interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `alama/arazzo-document` to eliminate the `ModelStack` anti-pattern, purify `DocumentInterface`, introduce `KnownOperations`, move preflight execution to `runner`, consolidate operation resolution SPIs, and enforce the public API boundary with an architecture test.

**Architecture:** Purify `alama/arazzo-document` into a vendor-free model, parser, and validator package. `Document` becomes a `final readonly` facade with a private constructor and static factory `Document::create()`. Preflight validation moves to `runner` as a protocol-aware pre-execution gate. Operation resolution is unified under `Alama\Arazzo\Document\OperationResolverInterface`.

**Tech Stack:** PHP 8.4, Pest 5, Composer, justinrainbow/json-schema, symfony/yaml.

---

### Task 1: `KnownOperations` Value Object in Document

**Files:**
- Create: `packages/document/src/KnownOperations.php`
- Test: `packages/document/tests/Validator/KnownOperationsTest.php`

- [ ] **Step 1: Write the failing test for `KnownOperations`**

Create `packages/document/tests/Validator/KnownOperationsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validator;

use Alama\Arazzo\Document\KnownOperations;

it('starts empty with none()', function (): void {
    $ops = KnownOperations::none();

    expect($ops->covers('petstore'))->toBeFalse()
        ->and($ops->has('petstore', 'getPet'))->toBeFalse()
        ->and($ops->toArray())->toBe([]);
});

it('records operations per source immutably', function (): void {
    $ops = KnownOperations::none()
        ->withSource('petstore', ['getPet', 'createPet'])
        ->withOperation('auth', 'login');

    expect($ops->covers('petstore'))->toBeTrue()
        ->and($ops->covers('auth'))->toBeTrue()
        ->and($ops->covers('unknown'))->toBeFalse()
        ->and($ops->has('petstore', 'getPet'))->toBeTrue()
        ->and($ops->has('petstore', 'deletePet'))->toBeFalse()
        ->and($ops->has('auth', 'login'))->toBeTrue()
        ->and($ops->toArray())->toBe([
            'petstore' => ['getPet' => true, 'createPet' => true],
            'auth' => ['login' => true],
        ]);
});

it('supports withSource with empty list to mark source as covered with zero operations', function (): void {
    $ops = KnownOperations::none()->withSource('emptyApi', []);

    expect($ops->covers('emptyApi'))->toBeTrue()
        ->and($ops->has('emptyApi', 'anyOp'))->toBeFalse()
        ->and($ops->toArray())->toBe(['emptyApi' => []]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest packages/document/tests/Validator/KnownOperationsTest.php`
Expected: FAIL with "Class Alama\Arazzo\Document\KnownOperations not found".

- [ ] **Step 3: Implement `KnownOperations`**

Create `packages/document/src/KnownOperations.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

final readonly class KnownOperations
{
    /**
     * @param array<string, array<string, true>> $operations [sourceName => [operationId => true]]
     */
    public function __construct(
        private array $operations = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param list<string> $operationIds
     */
    public function withSource(string $source, array $operationIds): self
    {
        $ops = $this->operations;
        $ops[$source] ??= [];
        foreach ($operationIds as $id) {
            $ops[$source][$id] = true;
        }

        return new self($ops);
    }

    public function withOperation(string $source, string $operationId): self
    {
        $ops = $this->operations;
        $ops[$source][$operationId] = true;

        return new self($ops);
    }

    public function covers(string $source): bool
    {
        return array_key_exists($source, $this->operations);
    }

    public function has(string $source, string $operationId): bool
    {
        return isset($this->operations[$source][$operationId]);
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function toArray(): array
    {
        return $this->operations;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest packages/document/tests/Validator/KnownOperationsTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add packages/document/src/KnownOperations.php packages/document/tests/Validator/KnownOperationsTest.php
git commit -m "feat(document): add KnownOperations value object"
```

---

### Task 2: Refactor SymbolTable & StepOperationIdResolvesRule to use `KnownOperations`

**Files:**
- Modify: `packages/document/src/Validator/Data/SymbolTable.php`
- Modify: `packages/document/src/Validator/Rules/StepOperationIdResolvesRule.php`
- Modify: `packages/document/src/Validator/Validator.php`
- Test: `packages/document/tests/Validator/Rules/StepOperationIdResolvesRuleTest.php`

- [ ] **Step 1: Check existing rule test**

Run: `./vendor/bin/pest packages/document/tests/Validator/Rules/StepOperationIdResolvesRuleTest.php`
Expected: PASS.

- [ ] **Step 2: Update `SymbolTable.php` to accept `KnownOperations`**

Modify `packages/document/src/Validator/Data/SymbolTable.php`:
Change constructor and `build()` to accept `?KnownOperations $knownOperations = null`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Data;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\ComponentWorkflow;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\KnownOperations;

/**
 * @internal
 */
final readonly class SymbolTable
{
    /**
     * @param  array<string, WorkflowSymbols>  $workflows
     * @param  array<string, SourceDescription>  $sources
     * @param  array<string, array<string, mixed>>  $components
     */
    public function __construct(
        public array $workflows = [],
        public array $sources = [],
        public array $components = [],
        public KnownOperations $knownOperations = new KnownOperations(),
    ) {}

    public static function build(ArazzoDocument $doc, ?KnownOperations $knownOperations = null): self
    {
        $workflows = [];
        $sources = [];
        $components = [];

        foreach ($doc->workflows as $w) {
            $workflows[$w->workflowId] = WorkflowSymbols::build($w);
        }

        foreach ($doc->sourceDescriptions as $s) {
            $sources[$s->name] = $s;
        }

        if ($doc->components !== null) {
            $components = [
                'inputs' => $doc->components->inputs,
                'parameters' => $doc->components->parameters,
                'successActions' => $doc->components->successActions,
                'failureActions' => $doc->components->failureActions,
            ];
        }

        return new self($workflows, $sources, $components, $knownOperations ?? KnownOperations::none());
    }
}
```

- [ ] **Step 3: Update `StepOperationIdResolvesRule.php` to use `KnownOperations`**

In `packages/document/src/Validator/Rules/StepOperationIdResolvesRule.php`, lines 50-63:
Replace `$resolved = $symbols->resolvedOperations[$sourceName] ?? null;` with:
```php
                if (!$symbols->knownOperations->covers($sourceName)) {
                    // No resolved operations available for this source (unreachable/skipped
                    // during validation per ADR-005): skip existence check so validation
                    // does not fail solely because a remote source could not be loaded.
                    continue;
                }

                if (!$symbols->knownOperations->has($sourceName, $operation)) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' uses unresolved operationId '{$operationId}'.",
                        "/workflows/{$wi}/steps/{$si}/operationId",
                    );
                }
```

- [ ] **Step 4: Update `Validator.php`**

In `packages/document/src/Validator/Validator.php`:
Change:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Document\Validator\Data\SymbolTable;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Validator
{
    public function __construct(
        private readonly RuleSet $rules,
        private readonly ?KnownOperations $knownOperations = null,
    ) {}

    public function validate(ArazzoDocument $doc, ?KnownOperations $knownOperations = null): ValidationResult
    {
        $ops = $knownOperations ?? $this->knownOperations ?? KnownOperations::none();
        $symbols = SymbolTable::build($doc, $ops);
        $collector = new ErrorCollector();

        foreach ($this->rules->activeRules() as $rule) {
            $rule->check($doc, $symbols, $collector);
        }

        return new ValidationResult($doc, $collector->errors(), $collector->warnings());
    }
}
```

- [ ] **Step 5: Run validator and rule tests to verify they pass**

Run: `./vendor/bin/pest packages/document/tests/Validator`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/document/src/Validator/ packages/document/tests/Validator/
git commit -m "refactor(document): use KnownOperations in SymbolTable and StepOperationIdResolvesRule"
```

---

### Task 3: Decoders Factory (`Decoders::for()`) & Public Interface

**Files:**
- Create: `packages/document/src/Parser/Decoders.php`
- Modify: `packages/document/src/Parser/Decoders/NativeJsonDecoder.php` (mark `@internal`)
- Modify: `packages/document/src/Parser/Decoders/SymfonyYamlDecoder.php` (mark `@internal`)
- Test: `packages/document/tests/Parser/DecodersTest.php`

- [ ] **Step 1: Write the failing test for `Decoders` factory**

Create `packages/document/tests/Parser/DecodersTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;

it('creates a decoder for JSON format', function (): void {
    $decoder = Decoders::for(Format::Json);

    expect($decoder)->toBeInstanceOf(DecoderInterface::class);
    $data = $decoder->decode('{"title":"test"}');
    expect($data)->toBe(['title' => 'test']);
});

it('creates a decoder for YAML format', function (): void {
    $decoder = Decoders::for(Format::Yaml);

    expect($decoder)->toBeInstanceOf(DecoderInterface::class);
    $data = $decoder->decode("title: test\n");
    expect($data)->toBe(['title' => 'test']);
});

it('passes custom decode policy to decoders', function (): void {
    $policy = DecodePolicy::default()->withMaxBytes(10);
    $decoder = Decoders::for(Format::Json, $policy);

    expect(fn () => $decoder->decode('{"very_long_key": "very_long_value"}'))
        ->toThrow(\Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest packages/document/tests/Parser/DecodersTest.php`
Expected: FAIL with "Class Alama\Arazzo\Document\Parser\Decoders not found".

- [ ] **Step 3: Implement `Decoders.php`**

Create `packages/document/src/Parser/Decoders.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Document\Parser\Decoders\NativeJsonDecoder;
use Alama\Arazzo\Document\Parser\Decoders\SymfonyYamlDecoder;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;

final readonly class Decoders
{
    public static function for(Format $format, ?DecodePolicy $policy = null): DecoderInterface
    {
        return match ($format) {
            Format::Json => new NativeJsonDecoder($policy),
            Format::Yaml => new SymfonyYamlDecoder($policy),
        };
    }
}
```

- [ ] **Step 4: Mark `NativeJsonDecoder` and `SymfonyYamlDecoder` as `@internal`**

Ensure docblocks in:
- `packages/document/src/Parser/Decoders/NativeJsonDecoder.php`
- `packages/document/src/Parser/Decoders/SymfonyYamlDecoder.php`
contain `@internal`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest packages/document/tests/Parser/DecodersTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add packages/document/src/Parser/Decoders.php packages/document/src/Parser/Decoders/ packages/document/tests/Parser/DecodersTest.php
git commit -m "feat(document): add Decoders::for factory and mark concrete decoders @internal"
```

---

### Task 4: Move Workflow Inputs Validation into `Document`

**Files:**
- Modify: `packages/document/src/Validator/Validator.php`
- Test: `packages/document/tests/Validator/ValidateInputsTest.php`

- [ ] **Step 1: Write failing unit test for `validateInputs`**

Create `packages/document/tests/Validator/ValidateInputsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validator;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Expression\ExpressionEngine;

it('validates inputs against workflow declared schema', function (): void {
    $validator = new Validator(RuleSet::default(new ExpressionEngine()));

    $doc = new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'wf-1',
                description: null,
                inputs: [
                    'type' => 'object',
                    'required' => ['userId'],
                    'properties' => [
                        'userId' => ['type' => 'integer'],
                    ],
                ],
            ),
        ],
    );

    // Valid inputs
    $validResult = $validator->validateInputs($doc, 'wf-1', ['userId' => 42]);
    expect($validResult->isValid())->toBeTrue()
        ->and($validResult->errors)->toBeEmpty();

    // Invalid inputs
    $invalidResult = $validator->validateInputs($doc, 'wf-1', ['userId' => 'not-an-int']);
    expect($invalidResult->isValid())->toBeFalse()
        ->and($invalidResult->errors)->toHaveCount(1)
        ->and($invalidResult->errors[0]->code)->toBe('preflight.inputs_schema');

    // Unknown workflow
    $unknownResult = $validator->validateInputs($doc, 'unknown-wf', []);
    expect($unknownResult->isValid())->toBeFalse()
        ->and($unknownResult->errors[0]->code)->toBe('preflight.unknown_workflow');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest packages/document/tests/Validator/ValidateInputsTest.php`
Expected: FAIL with "Call to undefined method Alama\Arazzo\Document\Validator\Validator::validateInputs()".

- [ ] **Step 3: Implement `validateInputs()` in `Validator.php`**

Add `validateInputs()` method to `packages/document/src/Validator/Validator.php`:
```php
    /**
     * Validates supplied runtime inputs against the workflow's declared
     * `inputs` JSON Schema (2020-12).
     *
     * @param  array<string, mixed>  $inputs
     */
    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult
    {
        $collector = new ErrorCollector();

        $workflow = null;
        foreach ($document->workflows as $candidate) {
            if ($candidate->workflowId === $workflowId) {
                $workflow = $candidate;
                break;
            }
        }

        if ($workflow === null) {
            return new ValidationResult($document, [
                new Data\Error('preflight.unknown_workflow', "Workflow '{$workflowId}' does not exist.", '/workflows/'.$workflowId),
            ], [], $inputs);
        }

        $schema = is_array($workflow->inputs ?? null) ? $workflow->inputs : null;

        if ($schema === null || $schema === []) {
            return new ValidationResult($document, [], [], $inputs);
        }

        $finalInputs = $inputs;
        $violations = $this->validateAgainstSchema($inputs, $schema, $finalInputs);

        foreach ($violations as $violation) {
            $pointer = '/workflows/'.$workflowId.'/inputs'
                .($violation['property'] !== ''
                    ? (string) preg_replace('/^\[([^\]]+)\]/', '/$1', $violation['property'])
                    : '');

            $collector->add(new Data\Error(
                'preflight.inputs_schema',
                $violation['message'],
                $pointer,
                severity: Enum\Severity::Error,
            ));
        }

        return new ValidationResult($document, $collector->errors(), $collector->warnings(), $finalInputs);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $populatedInputs
     * @return list<array{message: string, property: string}>
     */
    private function validateAgainstSchema(array $inputs, array $schema, array &$populatedInputs): array
    {
        $storage = new \JsonSchema\SchemaStorage();
        $schemaObject = json_decode((string) json_encode($schema), false);

        if (!$schemaObject instanceof \stdClass) {
            return [];
        }

        $schemaId = 'memory://inputs-schema';
        $storage->addSchema($schemaId, $schemaObject);

        $validator = new \JsonSchema\Validator();
        $dataObject = json_decode((string) json_encode($inputs ?: new \stdClass()), false);

        $validator->validate(
            $dataObject,
            $schemaObject,
            \JsonSchema\Constraints\Constraint::CHECK_MODE_APPLY_DEFAULTS,
        );

        if ($validator->isValid()) {
            $populated = json_decode((string) json_encode($dataObject), true);
            if (!is_array($populated)) {
                $populated = [];
            }
            $populatedInputs = $populated;

            return [];
        }

        $out = [];
        /** @var array{message: string, property?: string} $error */
        foreach ($validator->getErrors() as $error) {
            $out[] = [
                'message' => (string) $error['message'],
                'property' => is_string($error['property'] ?? null) ? $error['property'] : '',
            ];
        }

        return $out;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest packages/document/tests/Validator/ValidateInputsTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit**

```bash
git add packages/document/src/Validator/Validator.php packages/document/tests/Validator/ValidateInputsTest.php
git commit -m "feat(document): add validateInputs to Validator"
```

---

### Task 5: Redesign `Document` and `DocumentInterface` & Delete `ModelStack`

**Files:**
- Modify: `packages/document/src/DocumentInterface.php`
- Modify: `packages/document/src/Document.php`
- Delete: `packages/document/src/ModelStack.php`
- Modify: `packages/document/tests/DocumentTest.php`

- [ ] **Step 1: Update `DocumentInterface.php`**

Replace `packages/document/src/DocumentInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;

/**
 * Entry-point seam for the document package.
 *
 * Exposes loading, static document validation and workflow input validation.
 */
interface DocumentInterface
{
    /**
     * Load and parse an Arazzo document from a YAML/JSON file path.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function load(string $path): ArazzoDocument;

    /**
     * Load and parse an Arazzo document directly from a YAML string.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): ArazzoDocument;

    /**
     * Load and parse an Arazzo document directly from a JSON string.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function loadFromJson(string $content, string $virtualPath = 'inline.json'): ArazzoDocument;

    /**
     * Static validation of a parsed document against spec rules.
     */
    public function validate(ArazzoDocument $document, ?KnownOperations $operations = null): ValidationResult;

    /**
     * Validate supplied runtime inputs against a workflow's declared inputs
     * JSON Schema (2020-12).
     *
     * @param  array<string, mixed>  $inputs
     */
    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult;
}
```

- [ ] **Step 2: Update `Document.php`**

Replace `packages/document/src/Document.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Loader;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Expression\ExpressionEngine;

/**
 * Public façade of the *document* package.
 *
 * Pure model work – no I/O, no cebe, no Guzzle.
 */
final readonly class Document implements DocumentInterface
{
    private function __construct(
        private Loader $loader,
        private Parser $parser,
        private Validator $validator,
    ) {}

    public static function create(): self
    {
        $engine = new ExpressionEngine();

        return new self(
            loader: Loader::new(),
            parser: new Parser(),
            validator: new Validator(RuleSet::default($engine)),
        );
    }

    public function withDecodePolicy(DecodePolicy $policy): self
    {
        return new self(
            loader: Loader::defineDecodePolicy($policy),
            parser: $this->parser,
            validator: $this->validator,
        );
    }

    public function load(string $path): ArazzoDocument
    {
        return $this->parser->parse($this->loader->load($path));
    }

    public function loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): ArazzoDocument
    {
        return $this->parser->parse($this->loader->loadFromYaml($content, $virtualPath));
    }

    public function loadFromJson(string $content, string $virtualPath = 'inline.json'): ArazzoDocument
    {
        return $this->parser->parse($this->loader->loadFromJson($content, $virtualPath));
    }

    public function validate(ArazzoDocument $document, ?KnownOperations $operations = null): ValidationResult
    {
        return $this->validator->validate($document, $operations);
    }

    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult
    {
        return $this->validator->validateInputs($document, $workflowId, $inputs);
    }
}
```

- [ ] **Step 3: Delete `packages/document/src/ModelStack.php`**

Run: `git rm packages/document/src/ModelStack.php`

- [ ] **Step 4: Update `packages/document/tests/DocumentTest.php`**

Replace `packages/document/tests/DocumentTest.php` with:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;

function createDocument(): Document
{
    return Document::create();
}

it('loads and parses from YAML string via loadFromYaml', function (): void {
    $doc = createDocument();
    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Inline YAML\n  version: '1.0'\nworkflows: []\n";

    $result = $doc->loadFromYaml($yaml, 'custom.yaml');

    expect($result)->toBeInstanceOf(ArazzoDocument::class)
        ->and($result->info->title)->toBe('Inline YAML')
        ->and($result->info->version)->toBe('1.0');
});

it('loads and parses from JSON string via loadFromJson', function (): void {
    $doc = createDocument();
    $json = '{"arazzo":"1.0.0","info":{"title":"Inline JSON","version":"1.0"},"workflows":[]}';

    $result = $doc->loadFromJson($json);

    expect($result)->toBeInstanceOf(ArazzoDocument::class)
        ->and($result->info->title)->toBe('Inline JSON')
        ->and($result->info->version)->toBe('1.0');
});

it('configures decode policy immutably via withDecodePolicy on concrete Document', function (): void {
    $doc = createDocument();
    $strictDoc = $doc->withDecodePolicy(DecodePolicy::default()->withMaxBytes(40));

    expect($strictDoc)->toBeInstanceOf(Document::class)
        ->and($strictDoc)->not->toBe($doc);

    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Inline YAML that is definitely longer than forty bytes\n  version: '1.0'\nworkflows: []\n";

    expect(fn () => $strictDoc->loadFromYaml($yaml))
        ->toThrow(LoaderException::class, 'maxBytes');

    // Original doc is unaffected
    expect($doc->loadFromYaml($yaml))->toBeInstanceOf(ArazzoDocument::class);
});

it('validates document with known operations', function (): void {
    $doc = createDocument();
    $arazzoDoc = $doc->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nsourceDescriptions:\n  - name: myApi\n    url: https://example.com/api.json\n    type: openapi\nworkflows:\n  - workflowId: wf1\n    steps:\n      - stepId: step1\n        operationId: myApi.getPet\n");

    // Without knowing operations, remote source is skipped per ADR-005
    $resultNoOps = $doc->validate($arazzoDoc);
    expect($resultNoOps->isValid())->toBeTrue();

    // With known operations that do not contain getPet, it fails validation
    $knownOps = KnownOperations::none()->withSource('myApi', ['otherOp']);
    $resultFailed = $doc->validate($arazzoDoc, $knownOps);
    expect($resultFailed->isValid())->toBeFalse()
        ->and($resultFailed->errors[0]->code)->toBe('step.operation_id_resolves');
});

it('validates workflow runtime inputs', function (): void {
    $doc = createDocument();
    $arazzoDoc = new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'wf-1',
                description: null,
                inputs: [
                    'type' => 'object',
                    'required' => ['userId'],
                    'properties' => [
                        'userId' => ['type' => 'integer'],
                    ],
                ],
            ),
        ],
    );

    $valid = $doc->validateInputs($arazzoDoc, 'wf-1', ['userId' => 123]);
    expect($valid->isValid())->toBeTrue();

    $invalid = $doc->validateInputs($arazzoDoc, 'wf-1', ['userId' => 'abc']);
    expect($invalid->isValid())->toBeFalse();
});
```

- [ ] **Step 5: Run `DocumentTest`**

Run: `./vendor/bin/pest packages/document/tests/DocumentTest.php`
Expected: PASS (5 passed).

- [ ] **Step 6: Commit**

```bash
git add packages/document/src/ packages/document/tests/DocumentTest.php
git commit -m "refactor(document): eliminate ModelStack, purify DocumentInterface and add Document::create()"
```

---

### Task 6: Pest Architecture Test for Document Package Public API Allow-List

**Files:**
- Create: `packages/document/tests/ArchTest.php`

- [ ] **Step 1: Write `ArchTest.php`**

Create `packages/document/tests/ArchTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

arch('document package never depends on sources')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse('Alama\Arazzo\Sources');

arch('document package never depends on cebe or guzzle')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse(['cebe\openapi', 'GuzzleHttp']);

arch('Document is final and readonly')
    ->expect(\Alama\Arazzo\Document\Document::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('KnownOperations is final and readonly')
    ->expect(\Alama\Arazzo\Document\KnownOperations::class)
    ->toBeFinal()
    ->toBeReadonly();
```

- [ ] **Step 2: Run ArchTest**

Run: `./vendor/bin/pest packages/document/tests/ArchTest.php`
Expected: PASS (4 passed).

- [ ] **Step 3: Commit**

```bash
git add packages/document/tests/ArchTest.php
git commit -m "test(document): add architecture boundary tests for document package"
```

---

### Task 7: Sources Package Updates (Resolvers & Decoders)

**Files:**
- Modify: `packages/sources/src/Normalizer/OpenApiOperationResolver.php`
- Delete: `packages/sources/src/Normalizer/OpenApiOperationResolverInterface.php`
- Modify: `packages/sources/src/Resolver/DefaultSourceResolver.php`
- Modify: `packages/sources/src/SourceLoader.php`
- Modify: `packages/sources/src/SourceGraph.php`
- Test: `packages/sources/tests/SourceGraphTest.php`

- [ ] **Step 1: Update `OpenApiOperationResolver.php` to implement `Document\OperationResolverInterface`**

In `packages/sources/src/Normalizer/OpenApiOperationResolver.php`:
- Implement `Alama\Arazzo\Document\OperationResolverInterface`.
- Add `supportsStep(Step $step, ArazzoDocument $document): bool`.
- Ensure `resolve(Step $step, ArazzoDocument $document): OpenApiOperationHandle` is retained for handle callers, while implementing `resolveModel()` or ensuring `resolve(Step $step, ArazzoDocument $document): ResolvedOperation` satisfies `OperationResolverInterface`.
Wait, because PHP does not allow `resolve(): OpenApiOperationHandle` if the interface specifies `resolve(): ResolvedOperation`, we rename the handle-returning method to `resolveHandle(Step $step, ArazzoDocument $document): OpenApiOperationHandle` and have `resolve()` return `ResolvedOperation` (delegating to `resolveHandle($step, $document)->operation`).
Check callers of `resolve()`:
In `OpenApiOperationResolver`:
```php
    public function supportsStep(Step $step, ArazzoDocument $document): bool
    {
        return $step->target->operationId !== null || $step->target->operationPath !== null;
    }

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        return $this->resolveHandle($step, $document)->operation;
    }

    public function resolveModel(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        return $this->resolve($step, $document);
    }

    public function resolveHandle(Step $step, ArazzoDocument $document): OpenApiOperationHandle
    {
        ... // existing resolve() implementation
    }
```
Update callers in `StepExecutor.php` and `StepOutputExtractor.php` to call `resolveHandle()`.

- [ ] **Step 2: Delete `OpenApiOperationResolverInterface.php`**

Run: `git rm packages/sources/src/Normalizer/OpenApiOperationResolverInterface.php`

- [ ] **Step 3: Update `DefaultSourceResolver.php` to use `Decoders::for()`**

In `packages/sources/src/Resolver/DefaultSourceResolver.php`:
Replace direct `new SymfonyYamlDecoder($this->decodePolicy)` and `new NativeJsonDecoder($this->decodePolicy)` with:
```php
\Alama\Arazzo\Document\Parser\Decoders::for(Format::Yaml, $this->decodePolicy)->decode($content)
\Alama\Arazzo\Document\Parser\Decoders::for(Format::Json, $this->decodePolicy)->decode($content)
```

- [ ] **Step 4: Update `SourceLoader.php` to add `knownOperations()`**

In `packages/sources/src/SourceLoader.php`:
Add:
```php
    public function knownOperations(ArazzoDocument $document, string $basePath = ''): \Alama\Arazzo\Document\KnownOperations
    {
        $known = \Alama\Arazzo\Document\KnownOperations::none();
        $index = $this->normalizeSources($document, $basePath);
        foreach ($index as $key => $resolved) {
            $known = $known->withOperation($resolved->source->name, $key);
        }

        return $known;
    }
```

- [ ] **Step 5: Update `SourceGraph.php`**

In `packages/sources/src/SourceGraph.php`:
- Remove `document()` and `using()`.
- Update `runtime()`: callers construct `Document::create()` if needed, or `runtime()` instantiates `Document::create()` internally. Remove `$engine` parameter.

- [ ] **Step 6: Run sources tests**

Run: `./vendor/bin/pest packages/sources/tests`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/sources/
git commit -m "refactor(sources): implement OperationResolverInterface, use Decoders::for, and add knownOperations"
```

---

### Task 8: Runner Preflight & PreflightFailureException

**Files:**
- Create: `packages/runner/src/Preflight/Preflight.php`
- Create: `packages/runner/src/Preflight/OperationResolvers.php`
- Create: `packages/runner/src/Exceptions/PreflightFailureException.php`
- Delete: `packages/document/src/Validator/Exceptions/PreflightFailureException.php`
- Modify: `packages/runner/src/Sync/WorkflowExecutor.php`
- Modify: `packages/runner/src/Async/StepExecutionWorker.php`
- Modify: `packages/runner/src/Execution/Data/RunControlFlow.php`
- Test: `packages/runner/tests/Preflight/PreflightTest.php`

- [ ] **Step 1: Create `Alama\Arazzo\Runner\Exceptions\PreflightFailureException`**

Create `packages/runner/src/Exceptions/PreflightFailureException.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use RuntimeException;

final class PreflightFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ValidationResult $result,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

- [ ] **Step 2: Remove old `PreflightFailureException` in `document`**

Run: `git rm packages/document/src/Validator/Exceptions/PreflightFailureException.php`

- [ ] **Step 3: Create `OperationResolvers` composite in runner**

Create `packages/runner/src/Preflight/OperationResolvers.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Document\ResolvedOperation;
use RuntimeException;

final readonly class OperationResolvers
{
    /**
     * @param iterable<OperationResolverInterface> $resolvers
     */
    public function __construct(
        private iterable $resolvers,
    ) {}

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supportsStep($step, $document)) {
                return $resolver->resolve($step, $document);
            }
        }

        throw new RuntimeException("No operation resolver supports step '{$step->stepId}'.");
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supportsStep($step, $document)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Create `Preflight` in runner**

Create `packages/runner/src/Preflight/Preflight.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Validator\Data\Error;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Document\Validator\Enum\Severity;

final readonly class Preflight
{
    /**
     * @param list<string> $supportedXPathVersions
     */
    public function __construct(
        private OperationResolvers $resolvers,
        private array $supportedXPathVersions = ['xpath-10'],
    ) {}

    public function validate(ArazzoDocument $document): ValidationResult
    {
        $errors = [];

        foreach ($document->workflows as $workflow) {
            foreach ($workflow->steps as $step) {
                $this->checkStep($document, $workflow, $step, $errors);
            }
        }

        return new ValidationResult($document, $errors, []);
    }

    /**
     * @param list<Error> $errors
     */
    private function checkStep(ArazzoDocument $document, Workflow $workflow, Step $step, array &$errors): void
    {
        $base = '/workflows/'.$workflow->workflowId.'/steps/'.$step->stepId;

        // 1. If step targets an operation, check resolution through registered resolvers
        if ($step->target->operationId !== null || $step->target->operationPath !== null) {
            if (!$this->resolvers->supports($step, $document)) {
                $errors[] = new Error(
                    'preflight.no_resolver',
                    "No registered operation resolver supports step '{$step->stepId}'.",
                    $base.'/operationPath',
                    severity: Severity::Error,
                );

                return;
            }

            try {
                $this->resolvers->resolve($step, $document);
            } catch (\Throwable $e) {
                $errors[] = new Error(
                    'preflight.operation_unresolvable',
                    "Step '{$step->stepId}' operation resolution failed: {$e->getMessage()}",
                    $base.'/operationPath',
                    severity: Severity::Error,
                );

                return;
            }
        }

        // 2. Validate selector XPath versions
        foreach ($this->selectorsOf($step) as $selector) {
            $version = $selector->version ?? null;
            if ($version !== null && $selector->type->value === 'xpath') {
                if (!in_array($version, $this->supportedXPathVersions, true)) {
                    $errors[] = new Error(
                        'preflight.unsupported_selector_version',
                        "Selector requests XPath version '{$version}'; evaluator supports: ".implode(', ', $this->supportedXPathVersions).'.',
                        $base.'/parameters',
                        severity: Severity::Error,
                    );
                }
            }
        }
    }

    /** @return list<Selector> */
    private function selectorsOf(Step $step): array
    {
        $selectors = [];
        foreach ($step->io->parameters as $parameter) {
            if ($parameter->value instanceof Selector) {
                $selectors[] = $parameter->value;
            }
        }
        foreach ($step->io->outputs as $value) {
            if ($value instanceof Selector) {
                $selectors[] = $value;
            }
        }

        return $selectors;
    }
}
```

- [ ] **Step 5: Write unit test for `Preflight`**

Create `packages/runner/tests/Preflight/PreflightTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Tests\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Document\ResolvedOperation;
use Alama\Arazzo\Runner\Preflight\OperationResolvers;
use Alama\Arazzo\Runner\Preflight\Preflight;

it('validates steps using registered operation resolvers', function (): void {
    $resolver = new class() implements OperationResolverInterface {
        public function supportsStep(Step $step, ArazzoDocument $document): bool {
            return $step->target->operationId === 'validOp';
        }
        public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation {
            return \Mockery::mock(ResolvedOperation::class);
        }
    };

    $preflight = new Preflight(new OperationResolvers([$resolver]));

    $doc = new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'wf1',
                description: null,
                steps: [
                    new Step('s1', null, new StepTarget(operationId: 'validOp'), new StepFlow(), new StepIo()),
                    new Step('s2', null, new StepTarget(operationId: 'unsupportedOp'), new StepFlow(), new StepIo()),
                ],
            ),
        ],
    );

    $result = $preflight->validate($doc);
    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe('preflight.no_resolver');
});
```

- [ ] **Step 6: Update `RunControlFlow` and `WorkflowExecutor`**

- In `packages/runner/src/Execution/Data/RunControlFlow.php`:
  Update `public ?DocumentInterface $preflight = null` to `public ?Preflight $preflight = null` and `public bool $runPreflight = true`.
- In `packages/runner/src/Sync/WorkflowExecutor.php` & `StepExecutionWorker.php`:
  Use `Alama\Arazzo\Runner\Exceptions\PreflightFailureException`.
  Input validation calls `$this->document->validateInputs($document, $workflowId, $inputs)`.
  Preflight calls `$this->preflight->validate($document)`.

- [ ] **Step 7: Run preflight test**

Run: `./vendor/bin/pest packages/runner/tests/Preflight/PreflightTest.php`
Expected: PASS (1 passed).

- [ ] **Step 8: Commit**

```bash
git add packages/runner/ packages/document/src/Validator/Exceptions/
git commit -m "feat(runner): move Preflight and PreflightFailureException into runner"
```

---

### Task 9: Clean Up Sources PreflightValidator

**Files:**
- Delete: `packages/sources/src/Validator/PreflightValidator.php`
- Modify: `packages/sources/src/SourceLoader.php`
- Modify: `packages/sources/src/SourceGraph.php`

- [ ] **Step 1: Delete `PreflightValidator.php`**

Run: `git rm packages/sources/src/Validator/PreflightValidator.php`

- [ ] **Step 2: Remove references in `SourceLoader.php` and `SourceGraph.php`**

- In `SourceLoader.php`: remove `$preflight` constructor parameter and `preflightSource()` method.
- In `SourceGraph.php`: remove all `new PreflightValidator(...)` calls.

- [ ] **Step 3: Run sources tests**

Run: `./vendor/bin/pest packages/sources/tests`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/sources/
git commit -m "chore(sources): remove PreflightValidator from sources package"
```

---

### Task 10: CLI Package Updates

**Files:**
- Modify: `packages/cli/src/Console/Command/ValidateCommand.php`
- Modify: `packages/cli/src/Console/DocumentLoader.php`

- [ ] **Step 1: Update `ValidateCommand.php`**

In `packages/cli/src/Console/Command/ValidateCommand.php`:
- Import `Alama\Arazzo\Document\Document`.
- Import `Alama\Arazzo\Document\KnownOperations`.
- Remove imports of `Validator` and `RuleSet`.
- In `execute()`:
```php
        // Resolve source operations for reference validation (R4/R5).
        $knownOperations = KnownOperations::none();
        try {
            $loader = SourceGraph::loader();
            $knownOperations = $loader->knownOperations($document, (string) dirname($file));
        } catch (\Throwable $e) {
            // Degrade gracefully: never fail validation due to source loading.
        }

        $result = Document::create()->validate($document, $knownOperations);
```

- [ ] **Step 2: Update `DocumentLoader.php`**

In `packages/cli/src/Console/DocumentLoader.php`:
Replace body of `load()` with:
```php
        return Document::create()->load($file);
```

- [ ] **Step 3: Test `DocumentLoader` via pest**

Run: `./vendor/bin/pest packages/cli/tests/Console/ApplicationTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/cli/src/Console/
git commit -m "refactor(cli): use Document::create() and KnownOperations in ValidateCommand and DocumentLoader"
```

---

### Task 11: Verification & Regression Across Packages

**Files:**
- None (verification only)

- [ ] **Step 1: Run Document package test suite**

Run: `./vendor/bin/pest packages/document/tests`
Expected: ALL PASS.

- [ ] **Step 2: Run Sources package test suite**

Run: `./vendor/bin/pest packages/sources/tests`
Expected: ALL PASS.

- [ ] **Step 3: Run Document Architecture test**

Run: `./vendor/bin/pest packages/document/tests/ArchTest.php`
Expected: ALL PASS.

- [ ] **Step 4: Check git status to ensure clean working tree**

Run: `git status`
Expected: Clean working tree on branch `design-document-package-interface`.
