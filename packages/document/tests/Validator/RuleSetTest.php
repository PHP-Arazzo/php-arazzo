<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation;

use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Document\Plugins\ValidationRule\ReceiveStepSuccessCriteriaRule;
use Alama\Arazzo\Document\Validator\RuleSet;

class DummyRule implements ValidationRuleInterface
{
    public function __construct(private readonly string $c) {}

    public function code(): string
    {
        return $this->c;
    }

    public function check(ArazzoDocument $d, SymbolTable $s, ErrorCollector $e): void
    {
        $e->error($this->c, 'boom', '/');
    }
}

it('is immutable — withRule returns new instance', function (): void {
    $a = new RuleSet([]);
    $b = $a->withRule(new DummyRule('x'));
    expect($a->rules())->toBe([])
        ->and($b->rules())->toHaveCount(1);
});

it('honours disabled list from ValidatorPolicy', function (): void {
    $policy = ValidatorPolicy::default()->withDisabled('x');
    $set = (new RuleSet([], $policy))
        ->withRule(new DummyRule('x'))
        ->withRule(new DummyRule('y'));
    $codes = array_map(fn (ValidationRuleInterface $r) => $r->code(), $set->activeRules());
    expect($codes)->toBe(['y']);
});

it('exposes strict flag via isStrict() from ValidatorPolicy', function (): void {
    expect(RuleSet::default(ValidatorPolicy::default()->withStrict(true))->isStrict())->toBeTrue()
        ->and(RuleSet::default(ValidatorPolicy::default()->withStrict(false))->isStrict())->toBeFalse();
});

it('exposes conformance flag via isConformance() from ValidatorPolicy', function (): void {
    expect(RuleSet::default(ValidatorPolicy::default()->withConformance(true))->isConformance())->toBeTrue()
        ->and(RuleSet::default(ValidatorPolicy::default()->withConformance(false))->isConformance())->toBeFalse();
});

it('supports withPolicy immutably', function (): void {
    $set = RuleSet::default();
    $newSet = $set->withPolicy(ValidatorPolicy::default()->withDisabled('step.receive_missing_success_criteria'));

    expect($newSet->policy->isDisabled('step.receive_missing_success_criteria'))->toBeTrue()
        ->and($set->policy->isDisabled('step.receive_missing_success_criteria'))->toBeFalse();
});

it('registers the receive missing-criteria rule', function (): void {
    $codes = array_map(fn (ValidationRuleInterface $r) => $r->code(),
        RuleSet::default()->activeRules());

    expect($codes)->toContain('step.receive_missing_success_criteria');
});

it('warns rather than errors when a receive step omits successCriteria', function (): void {
    $rules = RuleSet::default()->activeRules();

    $receive = array_values(array_filter(
        $rules,
        static fn (ValidationRuleInterface $rule): bool => $rule instanceof ReceiveStepSuccessCriteriaRule,
    ));

    expect($receive)->toHaveCount(1)
        ->and($receive[0]->strict)->toBeFalse();
});

it('lets the receive missing-criteria rule be disabled via policy', function (): void {
    $policy = ValidatorPolicy::default()->withDisabled('step.receive_missing_success_criteria');
    $codes = array_map(fn (ValidationRuleInterface $r) => $r->code(),
        RuleSet::default($policy)->activeRules());

    expect($codes)->not->toContain('step.receive_missing_success_criteria');
});

it('registers the http missing-criteria rule by default', function (): void {
    $codes = array_map(fn (ValidationRuleInterface $r) => $r->code(),
        RuleSet::default()->activeRules());

    expect($codes)->toContain('step.http_missing_success_criteria');
});
