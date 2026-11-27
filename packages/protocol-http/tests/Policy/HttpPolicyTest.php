<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Protocol\Http\Policy\IdempotencyPolicy;
use Alama\Arazzo\Protocol\Http\Policy\NetworkPolicy;
use Alama\Arazzo\Protocol\Http\Policy\OutboundSafetyPolicy;
use Alama\Arazzo\Protocol\Http\Policy\RateLimitPolicy;
use Alama\Arazzo\Protocol\Http\Policy\RedirectPolicy;
use Alama\Arazzo\Protocol\Http\Policy\TransferPolicy;
use GuzzleHttp\Psr7\Uri;

it('constructs HttpPolicy with pure DDD value-object defaults', function (): void {
    $policy = HttpPolicy::default();

    expect($policy->strictValidation)->toBeTrue()
        ->and($policy->idempotency)->toEqual(IdempotencyPolicy::default())
        ->and($policy->idempotency->enabled)->toBeFalse()
        ->and($policy->idempotency->headerName)->toBe('Idempotency-Key')
        ->and($policy->rateLimit)->toEqual(RateLimitPolicy::default())
        ->and($policy->rateLimit->requestsPerSecond)->toBeNull()
        ->and($policy->rateLimit->burst)->toBe(1)
        ->and($policy->rateLimit->maxConcurrent)->toBe(4)
        ->and($policy->rateLimit->scope)->toBe(RateLimitScope::Host)
        ->and($policy->rateLimit->failFast)->toBeFalse()
        ->and($policy->rateLimit->isRateLimited())->toBeFalse()
        ->and($policy->safety)->toEqual(OutboundSafetyPolicy::default())
        ->and($policy->safety->redirects->maxRedirects)->toBe(10)
        ->and($policy->safety->redirects->allowDowngrade)->toBeFalse()
        ->and($policy->safety->network->allowedSchemes)->toBe(['http', 'https'])
        ->and($policy->safety->network->allowedHosts)->toBe([])
        ->and($policy->safety->network->deniedHosts)->toBe([])
        ->and($policy->safety->network->blockLinkLocal)->toBeTrue()
        ->and($policy->safety->network->blockPrivateNetworks)->toBeFalse()
        ->and($policy->safety->transfer->maxResponseBytes)->toBe(10_485_760)
        ->and($policy->safety->transfer->connectTimeout)->toBe(10.0)
        ->and($policy->sourceOverrides)->toBe([]);
});

it('produces immutable copies and supports single-level per-source overrides', function (): void {
    $base = HttpPolicy::default();
    $petstoreOverride = HttpPolicy::default()
        ->withStrictValidation(false)
        ->withIdempotency(IdempotencyPolicy::enabled('X-Idempotency-Key'))
        ->withRateLimit(RateLimitPolicy::default()->withRateLimit(10.5, 5)->withMaxConcurrent(8)->withScope('run')->withFailFast(true))
        ->withSafety(
            OutboundSafetyPolicy::default()
                ->withRedirects(RedirectPolicy::default()->withMaxRedirects(3)->withAllowDowngrade(true))
                ->withNetwork(NetworkPolicy::default()->withAllowedSchemes(['HTTPS'])->withAllowedHosts(['api.petstore.com']))
                ->withTransfer(TransferPolicy::default()->withMaxResponseBytes(1_048_576)->withConnectTimeout(2.5)),
        );

    $combined = $base->withSourceOverride('petstore', $petstoreOverride);

    expect($base->hasSourceOverride('petstore'))->toBeFalse()
        ->and($combined->hasSourceOverride('petstore'))->toBeTrue()
        ->and($combined->forSource('petstore'))->toBe($petstoreOverride)
        ->and($combined->forSource('unknown'))->toBe($combined)
        ->and($combined->forSource(null))->toBe($combined)
        ->and($combined->hasSourceOverride(' petstore '))->toBeTrue()
        ->and($combined->forSource(' petstore '))->toBe($petstoreOverride)
        ->and($combined->withoutSourceOverride(' petstore ')->hasSourceOverride('petstore'))->toBeFalse()
        ->and($petstoreOverride->rateLimit->isRateLimited())->toBeTrue()
        ->and($petstoreOverride->rateLimit->scope)->toBe(RateLimitScope::Run)
        ->and($petstoreOverride->safety->network->allowedSchemes)->toBe(['https'])
        ->and($combined->withoutSourceOverride('petstore')->hasSourceOverride('petstore'))->toBeFalse();
});

it('enforces RedirectPolicy domain predicates for hops, downgrades, and cross-origin detection', function (): void {
    $strict = RedirectPolicy::default()->withMaxRedirects(2);

    expect($strict->exceedsLimit(2))->toBeFalse()
        ->and($strict->exceedsLimit(3))->toBeTrue()
        ->and($strict->isDowngrade(new Uri('https://api.example.com/a'), new Uri('http://api.example.com/b')))->toBeTrue()
        ->and($strict->refusesDowngrade(new Uri('https://api.example.com/a'), new Uri('http://api.example.com/b')))->toBeTrue()
        ->and($strict->refusesDowngrade(new Uri('http://api.example.com/a'), new Uri('https://api.example.com/b')))->toBeFalse()
        ->and($strict->withAllowDowngrade(true)->refusesDowngrade(new Uri('https://api.example.com/a'), new Uri('http://api.example.com/b')))->toBeFalse()
        ->and($strict->isCrossOrigin(new Uri('https://api.example.com/a'), new Uri('https://api.example.com:443/b')))->toBeFalse()
        ->and($strict->isCrossOrigin(new Uri('https://api.example.com/a'), new Uri('https://cdn.example.com/b')))->toBeTrue()
        ->and($strict->isCrossOrigin(new Uri('https://api.example.com/a'), new Uri('https://api.example.com:8443/b')))->toBeTrue();
});

it('enforces NetworkPolicy domain predicates for schemes, host lists, link-local, and private networks', function (): void {
    $default = NetworkPolicy::default();

    expect($default->allowsScheme('https'))->toBeTrue()
        ->and($default->allowsScheme('HTTP'))->toBeTrue()
        ->and($default->allowsScheme('file'))->toBeFalse()
        ->and($default->allowsIp('169.254.169.254'))->toBeFalse()
        ->and($default->allowsIp('fe80::1'))->toBeFalse()
        ->and($default->allowsIp('0.0.0.0'))->toBeFalse()
        ->and($default->allowsIp('::'))->toBeFalse()
        ->and($default->allowsIp('127.0.0.1'))->toBeTrue()
        ->and($default->allowsIp('10.0.0.5'))->toBeTrue()
        ->and($default->allowsIp('93.184.216.34'))->toBeTrue();

    $strictPrivate = $default->withBlockPrivateNetworks(true);
    expect($strictPrivate->allowsIp('127.0.0.1'))->toBeFalse()
        ->and($strictPrivate->allowsIp('::1'))->toBeFalse()
        ->and($strictPrivate->allowsIp('10.1.2.3'))->toBeFalse()
        ->and($strictPrivate->allowsIp('172.16.0.1'))->toBeFalse()
        ->and($strictPrivate->allowsIp('192.168.1.10'))->toBeFalse()
        ->and($strictPrivate->allowsIp('fc00::1'))->toBeFalse()
        ->and($strictPrivate->allowsIp('::ffff:127.0.0.1'))->toBeFalse()
        ->and($strictPrivate->allowsIp('93.184.216.34'))->toBeTrue();

    $filtered = NetworkPolicy::default()
        ->withAllowedHosts(['*.example.com', 'api.petstore.com'])
        ->withDeniedHosts(['evil.example.com', '127.0.0.1']);

    expect($filtered->allowsHost('api.example.com'))->toBeTrue()
        ->and($filtered->allowsHost('api.petstore.com'))->toBeTrue()
        ->and($filtered->allowsHost('evil.example.com'))->toBeFalse()
        ->and($filtered->allowsHost('other.org'))->toBeFalse()
        ->and($filtered->allowsHost('127.0.0.1'))->toBeFalse();
});

it('enforces TransferPolicy response byte cap predicate', function (): void {
    $transfer = TransferPolicy::default()->withMaxResponseBytes(1024);

    expect($transfer->exceedsResponseCap(1024))->toBeFalse()
        ->and($transfer->exceedsResponseCap(1025))->toBeTrue();
});

it('rejects invalid policy invariants with InvalidHttpPolicyException', function (): void {
    expect(fn () => RateLimitPolicy::default()->withRateLimit(-1.0))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => RateLimitPolicy::default()->withRateLimit(10.0, 0))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => RateLimitPolicy::default()->withMaxConcurrent(0))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => IdempotencyPolicy::default()->withHeaderName('   '))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => RedirectPolicy::default()->withMaxRedirects(-1))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => NetworkPolicy::default()->withAllowedSchemes([]))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => TransferPolicy::default()->withMaxResponseBytes(0))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => TransferPolicy::default()->withConnectTimeout(0.0))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => HttpPolicy::default()->withSourceOverride('', HttpPolicy::default()))->toThrow(InvalidHttpPolicyException::class)
        ->and(fn () => HttpPolicy::default()->withSourceOverride(
            'petstore',
            HttpPolicy::default()->withSourceOverride('nested', HttpPolicy::default()),
        ))->toThrow(InvalidHttpPolicyException::class);
});
