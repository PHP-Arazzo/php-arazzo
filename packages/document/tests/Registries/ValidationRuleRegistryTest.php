<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Document\Registries;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;
use Alama\Arazzo\Document\Validator\OfficialSchemaRule;

#[AsValidationRule(priority: 500)]
final class HighPriorityCustomRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'custom.high_priority';
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

it('orders registered rules by #[AsValidationRule] priority descending', function (): void {
    $registry = ValidationRuleRegistry::create();
    $custom = new HighPriorityCustomRule();
    $registry->register($custom);

    expect($registry->get('custom.high_priority'))->toBe($custom)
        ->and($registry->all()[0])->toBe($custom);
});
