<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Observability;

use Throwable;

/**
 * Zero-overhead no-op span used by {@see NullTracer}.
 */
final readonly class NullSpan implements SpanInterface
{
    public function setAttribute(string $key, string|int|float|bool $value): void {}

    public function addEvent(string $name, array $attributes = []): void {}

    public function recordException(Throwable $exception): void {}

    public function setStatus(bool $ok, ?string $description = null): void {}

    public function end(): void {}
}
