<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Policy;

use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use InvalidArgumentException;

it('constructs with spec-aligned defaults', function (): void {
    $policy = HttpPolicy::default();

    expect($policy->strictValidation)->toBeTrue()
        ->and($policy->idempotencyInjector)->toBeNull()
        ->and($policy->requestsPerSecond)->toBeNull()
        ->and($policy->burst)->toBe(1)
        ->and($policy->maxConcurrent)->toBe(4)
        ->and($policy->respectRetryAfter)->toBeTrue()
        ->and($policy->scope)->toBe(RateLimitScope::Host)
        ->and($policy->failFast)->toBeFalse();
});

it('produces immutable modified copies via with* methods', function (): void {
    $base = HttpPolicy::default();
    $injector = new IdempotencyKeyInjector(true, 'Idempotency-Key');

    $custom = $base
        ->withStrictValidation(false)
        ->withIdempotencyInjector($injector)
        ->withRateLimit(10.5, 5)
        ->withMaxConcurrent(8)
        ->withScope(RateLimitScope::Run)
        ->withRespectRetryAfter(false)
        ->withFailFast(true);

    expect($base->strictValidation)->toBeTrue()
        ->and($base->idempotencyInjector)->toBeNull()
        ->and($base->requestsPerSecond)->toBeNull()
        ->and($base->burst)->toBe(1)
        ->and($base->maxConcurrent)->toBe(4)
        ->and($base->respectRetryAfter)->toBeTrue()
        ->and($base->scope)->toBe(RateLimitScope::Host)
        ->and($base->failFast)->toBeFalse()
        ->and($custom->strictValidation)->toBeFalse()
        ->and($custom->idempotencyInjector)->toBe($injector)
        ->and($custom->requestsPerSecond)->toBe(10.5)
        ->and($custom->burst)->toBe(5)
        ->and($custom->maxConcurrent)->toBe(8)
        ->and($custom->respectRetryAfter)->toBeFalse()
        ->and($custom->scope)->toBe(RateLimitScope::Run)
        ->and($custom->failFast)->toBeTrue();
});

it('accepts string scope in withScope and normalizes to RateLimitScope', function (): void {
    $policy = HttpPolicy::default()->withScope('run');

    expect($policy->scope)->toBe(RateLimitScope::Run);

    $hostPolicy = $policy->withScope('host');
    expect($hostPolicy->scope)->toBe(RateLimitScope::Host);
});

it('throws on invalid rate limit or concurrency values', function (): void {
    expect(fn () => HttpPolicy::default()->withRateLimit(-1.0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => HttpPolicy::default()->withRateLimit(10.0, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => HttpPolicy::default()->withMaxConcurrent(0))
        ->toThrow(InvalidArgumentException::class);
});
