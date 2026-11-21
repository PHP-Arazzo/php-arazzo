<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\ModelStack;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Expression\ExpressionEngine;

function createDocument(): DocumentInterface
{
    return new Document(ModelStack::default(new ExpressionEngine()));
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

it('configures decode policy immutably via withDecodePolicy', function (): void {
    $doc = createDocument();
    $strictDoc = $doc->withDecodePolicy(DecodePolicy::default()->withMaxBytes(40));

    expect($strictDoc)->toBeInstanceOf(DocumentInterface::class)
        ->and($strictDoc)->not->toBe($doc);

    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Inline YAML that is definitely longer than forty bytes\n  version: '1.0'\nworkflows: []\n";

    expect(fn () => $strictDoc->loadFromYaml($yaml))
        ->toThrow(LoaderException::class, 'maxBytes');

    // Original doc is unaffected
    expect($doc->loadFromYaml($yaml))->toBeInstanceOf(ArazzoDocument::class);
});

it('throws RuntimeException on resolveOperation when operationResolver is null', function (): void {
    $doc = createDocument();
    $arazzoDoc = $doc->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nworkflows: []\n");

    $stepWithTarget = new Step(
        stepId: 'step1',
        description: null,
        target: new StepTarget(operationId: 'getUser'),
        flow: new StepFlow(),
        io: new StepIo(),
    );

    expect(fn () => $doc->resolveOperation($stepWithTarget, $arazzoDoc))
        ->toThrow(\RuntimeException::class, 'No operation resolver available');

    $stepWithoutTarget = new Step(
        stepId: 'step2',
        description: null,
        target: new StepTarget(),
        flow: new StepFlow(),
        io: new StepIo(),
    );

    expect(fn () => $doc->resolveOperation($stepWithoutTarget, $arazzoDoc))
        ->toThrow(\RuntimeException::class, "Step 'step2' must have either operationId or operationPath.");
});
