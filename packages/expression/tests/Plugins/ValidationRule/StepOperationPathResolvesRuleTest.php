<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Plugins\ValidationRule\StepOperationPathResolvesRule;

function makePathDoc(
    array $steps,
    array $sources,
    SpecVersion $specVersion = SpecVersion::V1_1,
): ArazzoDocument {
    return new ArazzoDocument(
        arazzo: $specVersion->value,
        info: new Info('Test', null, null, '1.0'),
        sourceDescriptions: $sources,
        workflows: [new Workflow('w', null, null, null, [], $steps, [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: $specVersion,
    );
}

function makePathStep(string $id, ?string $opPath = null, ?string $opId = null): Step
{
    return StepFactory::http(
        stepId: $id,
        description: null,
        flow: new StepFlow(),
        io: new StepIo(),
        operationId: $opId,
        operationPath: $opPath,
    );
}

it('skips steps with null operationPath', function (): void {
    $doc = makePathDoc(
        steps: [makePathStep('a', opId: 'getThing')],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $ec = new ErrorCollector();
    $symbols = SymbolTable::build($doc, ['src' => ['getThing' => true]]);
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toBeEmpty();
});

it('skips malformed operationPath missing hash or invalid source expression', function (): void {
    $doc = makePathDoc(
        steps: [
            makePathStep('a', opPath: 'no-hash'),
            makePathStep('b', opPath: '{$invalid_expr}#/pets/get'),
        ],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $ec = new ErrorCollector();
    $symbols = SymbolTable::build($doc, ['src' => ['/pets/get' => true]]);
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toBeEmpty();
});

it('flags unknown source qualifier in operationPath', function (): void {
    $doc = makePathDoc(
        steps: [makePathStep('a', opPath: '{$sourceDescriptions.unknown.url}#/pets/get')],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('step.operationpath_resolves')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/steps/0/operationPath')
        ->and($ec->errors()[0]->message)->toContain("unknown source qualifier 'unknown'");
});

it('skips existence check when no resolved operations are available for source', function (): void {
    $doc = makePathDoc(
        steps: [makePathStep('a', opPath: '{$sourceDescriptions.src.url}#/paths/~1pets/get')],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, SymbolTable::build($doc, KnownOperations::none()), $ec);

    expect($ec->errors())->toBeEmpty();
});

it('flags unresolved operationPath when resolved operations are available', function (): void {
    $doc = makePathDoc(
        steps: [makePathStep('a', opPath: '{$sourceDescriptions.src.url}#/paths/~1pets/get')],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $symbols = SymbolTable::build($doc, KnownOperations::none()->withSource('src', ['/paths/~1other/get']));
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('step.operationpath_resolves')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/steps/0/operationPath')
        ->and($ec->errors()[0]->message)->toBe("Step 'a' uses unresolved operationPath '{\$sourceDescriptions.src.url}#/paths/~1pets/get'.");
});

it('accepts resolved operationPath matching exact pointer or hashed pointer', function (): void {
    $doc = makePathDoc(
        steps: [
            makePathStep('a', opPath: '{$sourceDescriptions.src.url}#/paths/~1pets/get'),
            makePathStep('b', opPath: '{$sourceDescriptions.src.url}#/paths/~1items~1{id}/post'),
        ],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $knownOps = KnownOperations::none()->withSource('src', [
        '/paths/~1pets/get',
        '#/paths/~1items~1{id}/post',
    ]);
    $symbols = SymbolTable::build($doc, $knownOps);
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toBeEmpty();
});

it('accepts resolved operationPath with case-insensitive HTTP method', function (): void {
    $doc = makePathDoc(
        steps: [
            makePathStep('a', opPath: '{$sourceDescriptions.src.url}#/paths/~1pets/GET'),
        ],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
    );
    $knownOps = KnownOperations::none()->withSource('src', [
        '/paths/~1pets/get',
    ]);
    $symbols = SymbolTable::build($doc, $knownOps);
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toBeEmpty();
});

it('supports legacy ${...} source URL expression under v1.0.0', function (): void {
    $doc = makePathDoc(
        steps: [
            makePathStep('a', opPath: '${sourceDescriptions.src.url}#/paths/~1pets/get'),
        ],
        sources: [new SourceDescription('src', '/u', SourceType::Openapi)],
        specVersion: SpecVersion::V1_0,
    );
    $knownOps = KnownOperations::none()->withSource('src', ['/paths/~1pets/get']);
    $symbols = SymbolTable::build($doc, $knownOps);
    $ec = new ErrorCollector();
    (new StepOperationPathResolvesRule())->check($doc, $symbols, $ec);

    expect($ec->errors())->toBeEmpty();
});
