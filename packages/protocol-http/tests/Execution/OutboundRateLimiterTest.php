<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Exceptions\RateLimitExceededException;
use Alama\Arazzo\Protocol\Http\Execution\OutboundRateLimiter;
use Alama\Arazzo\Protocol\Http\Policy\RateLimitPolicy;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Request;
use Mockery;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class MutableTestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-11-27 12:00:00.000000')
    {
        $this->now = new DateTimeImmutable($time);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceMicroseconds(int $microseconds): void
    {
        $seconds = (int) ($microseconds / 1_000_000);
        $remainderUs = $microseconds % 1_000_000;
        $this->now = $this->now->modify("+{$seconds} seconds {$remainderUs} microseconds");
    }

    public function advanceSeconds(float $seconds): void
    {
        $us = (int) ($seconds * 1_000_000);
        $this->advanceMicroseconds($us);
    }
}

it('resolves target keys based on policy scope', function (): void {
    $clock = new MutableTestClock();
    $limiter = new OutboundRateLimiter($clock);

    $req1 = new Request('GET', 'https://API.Example.com/users');
    $req2 = new Request('GET', 'http://api.example.com:8080/users');

    expect($limiter->resolveTargetKey($req1, RateLimitScope::Host))->toBe('api.example.com')
        ->and($limiter->resolveTargetKey($req2, RateLimitScope::Host))->toBe('api.example.com:8080')
        ->and($limiter->resolveTargetKey($req1, RateLimitScope::Run))->toBe('__global_run__');
});

it('enforces requests per second and burst using token bucket (S1 & S4)', function (): void {
    $clock = new MutableTestClock();
    $sleptUs = 0;
    $sleeper = function (int $us) use (&$sleptUs, $clock): void {
        $sleptUs += $us;
        $clock->advanceMicroseconds($us);
    };

    $limiter = new OutboundRateLimiter($clock, $sleeper);
    $policy = RateLimitPolicy::default()->withRateLimit(10.0, 1); // 10 req/s = 0.1s per token, burst = 1

    $logger = Mockery::mock(LoggerInterface::class);
    $span = Mockery::mock(SpanInterface::class);

    // First request consumes initial burst token immediately without wait
    $limiter->acquire('api.example.com', $policy, $logger, $span);
    $limiter->release('api.example.com');
    expect($sleptUs)->toBe(0);

    // Second request within the same instant must be throttled and wait 0.1s (100,000 us)
    $logger->shouldReceive('warning')
        ->once()
        ->with('transport.throttled', Mockery::on(function (array $context): bool {
            return isset($context['wait_seconds']) && $context['wait_seconds'] > 0.09
                && ($context['scope'] ?? null) === 'api.example.com';
        }));

    $span->shouldReceive('addEvent')
        ->once()
        ->with('transport.throttled', Mockery::type('array'));

    $limiter->acquire('api.example.com', $policy, $logger, $span);
    $limiter->release('api.example.com');

    expect($sleptUs)->toBe(100000);
});

it('caps in-flight requests according to maxConcurrent (S2)', function (): void {
    $clock = new MutableTestClock();
    $sleptUs = 0;
    $sleeper = function (int $us) use (&$sleptUs, $clock): void {
        $sleptUs += $us;
        $clock->advanceMicroseconds($us);
    };

    $limiter = new OutboundRateLimiter($clock, $sleeper);
    $policy = RateLimitPolicy::default()->withMaxConcurrent(2);

    // Acquire 2 slots
    $limiter->acquire('api.example.com', $policy);
    $limiter->acquire('api.example.com', $policy);

    expect($limiter->activeInFlight('api.example.com'))->toBe(2);

    // 3rd slot with fail-fast throws
    $failFastPolicy = $policy->withFailFast(true);
    expect(fn () => $limiter->acquire('api.example.com', $failFastPolicy))
        ->toThrow(RateLimitExceededException::class);

    // Releasing 1 slot allows a subsequent acquisition
    $limiter->release('api.example.com');
    expect($limiter->activeInFlight('api.example.com'))->toBe(1);

    $limiter->acquire('api.example.com', $policy);
    expect($limiter->activeInFlight('api.example.com'))->toBe(2);

    $limiter->release('api.example.com');
    $limiter->release('api.example.com');
    expect($limiter->activeInFlight('api.example.com'))->toBe(0);
});

it('produces transport.rate_limited with failFast without sending (S5)', function (): void {
    $clock = new MutableTestClock();
    $limiter = new OutboundRateLimiter($clock);
    $policy = RateLimitPolicy::default()
        ->withRateLimit(1.0, 1) // 1 token capacity
        ->withFailFast(true);

    // First request consumes token
    $limiter->acquire('api.example.com', $policy);
    $limiter->release('api.example.com');

    // Second request with no tokens left throws immediately with code transport.rate_limited
    try {
        $limiter->acquire('api.example.com', $policy);
        test()->fail('Expected RateLimitExceededException was not thrown');
    } catch (RateLimitExceededException $e) {
        expect($e->getErrorCode())->toBe('transport.rate_limited');
    }
});

it('isolates buckets by host in perHost scope and shares in perRun scope', function (): void {
    $clock = new MutableTestClock();
    $limiter = new OutboundRateLimiter($clock);
    $policy = RateLimitPolicy::default()
        ->withRateLimit(1.0, 1)
        ->withFailFast(true);

    // Host A consumes its token
    $limiter->acquire('api.a.com', $policy);
    $limiter->release('api.a.com');

    // Host B still has its own token and does not throw
    $limiter->acquire('api.b.com', $policy);
    $limiter->release('api.b.com');

    // Under perRun scope, both targets share __global_run__
    $runPolicy = $policy->withScope(RateLimitScope::Run);
    $limiter->acquire('__global_run__', $runPolicy);
    $limiter->release('__global_run__');

    // Immediate second acquisition under __global_run__ fails fast
    expect(fn () => $limiter->acquire('__global_run__', $runPolicy))
        ->toThrow(RateLimitExceededException::class);
});
