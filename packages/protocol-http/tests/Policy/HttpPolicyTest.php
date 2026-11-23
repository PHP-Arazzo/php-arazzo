<?php

declare(strict_types=1);

use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;

it('constructs with spec-aligned defaults', function (): void {
    $policy = HttpPolicy::default();

    expect($policy->strictValidation)->toBeTrue()
        ->and($policy->idempotencyInjector)->toBeNull();
});

it('produces immutable modified copies via with* methods', function (): void {
    $base = HttpPolicy::default();
    $injector = new IdempotencyKeyInjector(true, 'Idempotency-Key');

    $custom = $base
        ->withStrictValidation(false)
        ->withIdempotencyInjector($injector);

    expect($base->strictValidation)->toBeTrue()
        ->and($base->idempotencyInjector)->toBeNull()
        ->and($custom->strictValidation)->toBeFalse()
        ->and($custom->idempotencyInjector)->toBe($injector);
});
