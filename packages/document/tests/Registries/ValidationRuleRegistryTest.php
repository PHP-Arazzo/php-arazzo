<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Document\Registries;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidationPhase;
use Alama\Arazzo\Document\Plugins\ValidationRule\OfficialSchemaRule;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;

#[AsValidationRulePlugin(ValidationPhase::Schema)]
final class CustomSchemaPhaseRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'custom.schema_phase';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void {}
}

#[AsValidationRulePlugin(ValidationPhase::Semantic)]
final class OverrideSchemaRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'custom.schema_phase';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void {}
}

beforeEach(function (): void {
    ValidationRuleRegistry::reset();
});

afterEach(function (): void {
    ValidationRuleRegistry::reset();
});

it('operates as a singleton with create() and reset() and auto-discovers rules across installed packages', function (): void {
    $a = ValidationRuleRegistry::create();
    $b = ValidationRuleRegistry::create();

    expect($a)->toBe($b)
        ->and($a->has('schema.invalid'))->toBeTrue()
        ->and($a->has('expr.syntax'))->toBeTrue()
        ->and($a->has('expr.jsonpath_condition'))->toBeTrue()
        ->and($a->has('step.wsdl_step'))->toBeTrue()
        ->and($a->has('step.rpc_step'))->toBeTrue()
        ->and($a->all()[0])->toBeInstanceOf(OfficialSchemaRule::class);

    ValidationRuleRegistry::reset();
    $c = ValidationRuleRegistry::create();

    expect($c)->not->toBe($a);
});

it('orders Schema-phase rules before Semantic-phase rules and lets last-registered win on duplicate code', function (): void {
    $registry = ValidationRuleRegistry::create();
    $schemaRule = new CustomSchemaPhaseRule();
    $registry->register($schemaRule);

    expect($registry->get('custom.schema_phase'))->toBe($schemaRule)
        ->and($registry->all()[1])->toBe($schemaRule);

    $override = new OverrideSchemaRule();
    $registry->register($override);

    expect($registry->get('custom.schema_phase'))->toBe($override);
});
