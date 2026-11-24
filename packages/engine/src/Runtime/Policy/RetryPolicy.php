<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runtime\Policy;

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Interfaces\BackoffCalculatorInterface;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\InvalidRetryPolicyException;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Immutable policy governing retry ceilings, backoff calculation, and
 * `Retry-After` HTTP-date delay resolution against a PSR-20 clock.
 */
final readonly class RetryPolicy
{
    public const int DEFAULT_MAX_ATTEMPTS = 10;

    public const float DEFAULT_BACKOFF_MULTIPLIER = 1.0;

    public BackoffCalculatorInterface $calculator;

    public ClockInterface $clock;

    public function __construct(
        public int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        public float $backoffMultiplier = self::DEFAULT_BACKOFF_MULTIPLIER,
        ?BackoffCalculatorInterface $calculator = null,
        ?ClockInterface $clock = null,
    ) {
        if ($maxAttempts < 1) {
            throw InvalidRetryPolicyException::nonPositiveMaxAttempts();
        }

        if ($backoffMultiplier <= 0.0) {
            throw InvalidRetryPolicyException::invalidBackoffMultiplier();
        }

        $this->calculator = $calculator ?? new ExponentialBackoffCalculator();
        $this->clock = $clock ?? new SystemClock();
    }

    public static function default(): self
    {
        return new self();
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            backoffMultiplier: $this->backoffMultiplier,
            calculator: $this->calculator,
            clock: $this->clock,
        );
    }

    public function withBackoffMultiplier(float $multiplier): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffMultiplier: $multiplier,
            calculator: $this->calculator,
            clock: $this->clock,
        );
    }

    public function withCalculator(BackoffCalculatorInterface $calculator): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffMultiplier: $this->backoffMultiplier,
            calculator: $calculator,
            clock: $this->clock,
        );
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffMultiplier: $this->backoffMultiplier,
            calculator: $this->calculator,
            clock: $clock,
        );
    }

    public function calculateDelay(RetryAction $action, Step $step, WorkflowContext $context, int $upcomingAttempt): int
    {
        $headerValue = self::lookupHeader($context, $step->stepId, 'Retry-After');

        if ($headerValue !== null) {
            $trimmed = trim($headerValue);
            if (preg_match('/^\d+$/', $trimmed) === 1) {
                return max(0, (int) $trimmed);
            }

            $date = DateTimeImmutable::createFromFormat(\DATE_RFC7231, $trimmed);
            if ($date !== false) {
                return max(0, $date->getTimestamp() - $this->clock->now()->getTimestamp());
            }
        }

        $base = $action->retryAfter ?? 0;

        return $this->calculator->calculate($base, $upcomingAttempt, $this->backoffMultiplier);
    }

    public function isExhausted(int $attemptsSoFar, ?int $limit): bool
    {
        $effectiveLimit = $limit ?? PHP_INT_MAX;
        $effectiveLimit = min($effectiveLimit, $this->maxAttempts);

        return $attemptsSoFar >= $effectiveLimit;
    }

    private static function lookupHeader(WorkflowContext $context, string $stepId, string $name): ?string
    {
        $steps = $context->getSteps();
        $stepData = $steps[$stepId] ?? null;
        $response = is_array($stepData) ? ($stepData['response'] ?? null) : null;
        if (!is_array($response)) {
            return null;
        }

        $headers = $response['headers'] ?? [];
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0 && is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
