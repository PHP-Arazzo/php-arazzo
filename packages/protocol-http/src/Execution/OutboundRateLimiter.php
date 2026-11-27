<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Exceptions\RateLimitExceededException;
use Alama\Arazzo\Protocol\Http\Policy\RateLimitPolicy;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces token-bucket rate limits and in-flight concurrency caps
 * at the outbound HTTP transport boundary.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class OutboundRateLimiter
{
    public const int CONCURRENCY_WAIT_MICROSECONDS = 5000;

    public const float MAX_CONCURRENCY_WAIT_SECONDS = 30.0;

    /**
     * @var array<string, array{tokens: float, lastRefillTimestamp: float}>
     */
    private array $buckets = [];

    /**
     * @var array<string, int>
     */
    private array $activeInFlight = [];

    private readonly ClockInterface $clock;

    /**
     * @var callable(int): void
     */
    private readonly mixed $sleeper;

    /**
     * @param  (callable(int): void)|null  $sleeper  Microsecond sleeper callable for deterministic test time advancement.
     */
    public function __construct(
        ?ClockInterface $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function resolveTargetKey(RequestInterface $request, RateLimitScope $scope): string
    {
        if ($scope === RateLimitScope::Run) {
            return '__global_run__';
        }

        $host = strtolower($request->getUri()->getHost());
        $port = $request->getUri()->getPort();

        return $port !== null ? "{$host}:{$port}" : $host;
    }

    /**
     * @throws RateLimitExceededException
     */
    public function acquire(
        string $targetKey,
        RateLimitPolicy $policy,
        ?LoggerInterface $logger = null,
        ?SpanInterface $span = null,
    ): void {
        $this->acquireToken($targetKey, $policy, $logger, $span);
        $this->acquireConcurrencySlot($targetKey, $policy);
    }

    public function release(string $targetKey): void
    {
        if (isset($this->activeInFlight[$targetKey]) && $this->activeInFlight[$targetKey] > 0) {
            $this->activeInFlight[$targetKey]--;
        }
    }

    public function activeInFlight(string $targetKey): int
    {
        return $this->activeInFlight[$targetKey] ?? 0;
    }

    private function acquireToken(
        string $targetKey,
        RateLimitPolicy $policy,
        ?LoggerInterface $logger,
        ?SpanInterface $span,
    ): void {
        if ($policy->requestsPerSecond === null) {
            return;
        }

        $now = $this->currentTimestamp();
        $rps = $policy->requestsPerSecond;
        $burst = (float) $policy->burst;

        if (!isset($this->buckets[$targetKey])) {
            $this->buckets[$targetKey] = [
                'tokens' => $burst,
                'lastRefillTimestamp' => $now,
            ];
        }

        $bucket = &$this->buckets[$targetKey];
        $elapsed = max(0.0, $now - $bucket['lastRefillTimestamp']);
        $bucket['tokens'] = min($burst, $bucket['tokens'] + ($elapsed * $rps));
        $bucket['lastRefillTimestamp'] = $now;

        if ($bucket['tokens'] < 1.0) {
            if ($policy->failFast) {
                throw RateLimitExceededException::rateLimited($targetKey, $rps);
            }

            $deficit = 1.0 - $bucket['tokens'];
            $waitSeconds = $deficit / $rps;

            $logger?->warning('transport.throttled', [
                'wait_seconds' => $waitSeconds,
                'scope' => $targetKey,
            ]);

            $span?->addEvent('transport.throttled', [
                'duration_ms' => (int) round($waitSeconds * 1000),
                'scope' => $targetKey,
            ]);

            ($this->sleeper)((int) round($waitSeconds * 1_000_000));

            $bucket['tokens'] = 0.0;
            $bucket['lastRefillTimestamp'] = $this->currentTimestamp();
        } else {
            $bucket['tokens'] -= 1.0;
        }
    }

    private function acquireConcurrencySlot(string $targetKey, RateLimitPolicy $policy): void
    {
        $current = $this->activeInFlight[$targetKey] ?? 0;
        if ($current < $policy->maxConcurrent) {
            $this->activeInFlight[$targetKey] = $current + 1;

            return;
        }

        if ($policy->failFast) {
            throw RateLimitExceededException::concurrencyCapped($targetKey, $policy->maxConcurrent);
        }

        $start = $this->currentTimestamp();
        while (($this->activeInFlight[$targetKey] ?? 0) >= $policy->maxConcurrent) {
            if (($this->currentTimestamp() - $start) >= self::MAX_CONCURRENCY_WAIT_SECONDS) {
                throw RateLimitExceededException::concurrencyCapped($targetKey, $policy->maxConcurrent);
            }

            ($this->sleeper)(self::CONCURRENCY_WAIT_MICROSECONDS);
        }

        $this->activeInFlight[$targetKey] = ($this->activeInFlight[$targetKey] ?? 0) + 1;
    }

    private function currentTimestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
