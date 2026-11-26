<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Contracts\Validation\Warning;

it('builds immutable ValidatorPolicy with default values', function (): void {
    $policy = ValidatorPolicy::default();

    expect($policy->severities)->toBe([])
        ->and($policy->disabled)->toBe([])
        ->and($policy->strict)->toBeTrue()
        ->and($policy->isStrict())->toBeTrue()
        ->and($policy->conformance)->toBeFalse()
        ->and($policy->isConformance())->toBeFalse()
        ->and($policy->isDisabled('any.rule'))->toBeFalse()
        ->and($policy->severityFor('any.rule'))->toBe(Severity::Error);
});

it('supports withConformance immutably', function (): void {
    $base = ValidatorPolicy::default();
    $conformance = $base->withConformance(true);
    $back = $conformance->withConformance(false);

    expect($base->isConformance())->toBeFalse()
        ->and($conformance->isConformance())->toBeTrue()
        ->and($back->isConformance())->toBeFalse();
});

it('supports withSeverity, withDisabled, withEnabled, and withStrict immutably', function (): void {
    $base = ValidatorPolicy::default();

    $p1 = $base->withSeverity('rule.a', Severity::Warning)
        ->withSeverity('rule.b', 'warning');
    $p2 = $p1->withDisabled('rule.x', 'rule.y');
    $p3 = $p2->withEnabled('rule.x');
    $p4 = $p3->withStrict(false);

    expect($base->severities)->toBe([])
        ->and($p1->severityFor('rule.a'))->toBe(Severity::Warning)
        ->and($p1->severityFor('rule.b'))->toBe(Severity::Warning)
        ->and($p1->severityFor('rule.c'))->toBe(Severity::Error)
        ->and($p2->disabled)->toBe(['rule.x', 'rule.y'])
        ->and($p2->isDisabled('rule.x'))->toBeTrue()
        ->and($p3->disabled)->toBe(['rule.y'])
        ->and($p3->isDisabled('rule.x'))->toBeFalse()
        ->and($p4->isStrict())->toBeFalse();
});

it('remaps severity in ErrorCollector based on ValidatorPolicy (bidirectional and add normalization)', function (): void {
    $policy = ValidatorPolicy::default()
        ->withSeverity('noisy.rule', Severity::Warning)
        ->withSeverity('strict.warning.rule', Severity::Error);

    $collector = new ErrorCollector($policy);

    // 1. error() demoted to warning
    $collector->error('noisy.rule', 'demoted finding', '/path/1');
    expect($collector->errors())->toHaveCount(0)
        ->and($collector->warnings())->toHaveCount(1)
        ->and($collector->warnings()[0])->toBeInstanceOf(Warning::class)
        ->and($collector->warnings()[0]->code)->toBe('noisy.rule')
        ->and($collector->warnings()[0]->message)->toBe('demoted finding')
        ->and($collector->warnings()[0]->path)->toBe('/path/1');

    // 2. warning() elevated to error
    $collector->warning('strict.warning.rule', 'elevated finding', '/path/2');
    expect($collector->errors())->toHaveCount(1)
        ->and($collector->warnings())->toHaveCount(1)
        ->and($collector->errors()[0])->toBeInstanceOf(Error::class)
        ->and($collector->errors()[0]->code)->toBe('strict.warning.rule')
        ->and($collector->errors()[0]->message)->toBe('elevated finding')
        ->and($collector->errors()[0]->path)->toBe('/path/2');

    // 3. untouched error and warning remain unchanged
    $collector->error('standard.error', 'stays error', '/path/3');
    $collector->warning('standard.warn', 'stays warning', '/path/4');
    expect($collector->errors())->toHaveCount(2)
        ->and($collector->warnings())->toHaveCount(2);

    // 4. add() normalizes Error to Warning and Warning to Error
    $collector->add(new Error('noisy.rule', 'demoted via add', '/path/5'));
    $collector->add(new Warning('strict.warning.rule', 'elevated via add', '/path/6'));

    expect($collector->errors())->toHaveCount(3)
        ->and($collector->warnings())->toHaveCount(3)
        ->and($collector->warnings()[2]->message)->toBe('demoted via add')
        ->and($collector->errors()[2]->message)->toBe('elevated via add');
});

it('preserves default ErrorCollector behavior when no policy is provided', function (): void {
    $collector = new ErrorCollector();

    $collector->error('any.rule', 'error msg', '/p1');
    $collector->warning('any.rule', 'warn msg', '/p2');
    $collector->add(new Error('any.rule', 'add err', '/p3'));
    $collector->add(new Warning('any.rule', 'add warn', '/p4'));

    expect($collector->errors())->toHaveCount(2)
        ->and($collector->warnings())->toHaveCount(2);
});
