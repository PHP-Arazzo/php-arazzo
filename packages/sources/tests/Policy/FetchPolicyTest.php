<?php

declare(strict_types=1);

use Alama\Arazzo\Sources\Policy\FetchPolicy;

it('constructs with spec-aligned defaults', function (): void {
    $policy = FetchPolicy::default();

    expect($policy->connectTimeout)->toBe(FetchPolicy::DEFAULT_CONNECT_TIMEOUT)
        ->and($policy->timeout)->toBe(FetchPolicy::DEFAULT_TIMEOUT)
        ->and($policy->maxBytes)->toBe(FetchPolicy::DEFAULT_MAX_BYTES)
        ->and($policy->allowedSchemes)->toBe(['http', 'https', 'file'])
        ->and($policy->strictReachability)->toBeFalse()
        ->and($policy->allowsScheme('HTTP'))->toBeTrue()
        ->and($policy->allowsScheme('https'))->toBeTrue()
        ->and($policy->allowsScheme('file'))->toBeTrue()
        ->and($policy->allowsScheme('ftp'))->toBeFalse();
});

it('produces immutable modified copies via fluent with* methods', function (): void {
    $base = FetchPolicy::default();
    $custom = $base
        ->withConnectTimeout(1.5)
        ->withTimeout(12.0)
        ->withMaxBytes(1024)
        ->withAllowedSchemes(['HTTPS', 'https', 'file'])
        ->withStrictReachability();

    expect($base->connectTimeout)->toBe(2.0)
        ->and($base->timeout)->toBe(5.0)
        ->and($base->maxBytes)->toBe(FetchPolicy::DEFAULT_MAX_BYTES)
        ->and($base->strictReachability)->toBeFalse()
        ->and($custom->connectTimeout)->toBe(1.5)
        ->and($custom->timeout)->toBe(12.0)
        ->and($custom->maxBytes)->toBe(1024)
        ->and($custom->allowedSchemes)->toBe(['https', 'file'])
        ->and($custom->strictReachability)->toBeTrue()
        ->and($custom->allowsScheme('http'))->toBeFalse()
        ->and($custom->allowsScheme('https'))->toBeTrue();
});

it('rejects non-positive connectTimeout', function (): void {
    FetchPolicy::default()->withConnectTimeout(0.0);
})->throws(InvalidArgumentException::class, 'connectTimeout');

it('rejects non-positive timeout', function (): void {
    FetchPolicy::default()->withTimeout(-1.0);
})->throws(InvalidArgumentException::class, 'timeout');

it('rejects maxBytes below 1', function (): void {
    FetchPolicy::default()->withMaxBytes(0);
})->throws(InvalidArgumentException::class, 'maxBytes');
