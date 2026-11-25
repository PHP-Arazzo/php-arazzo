<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Observability;

use Throwable;

/**
 * Vendor-agnostic handle for an active distributed tracing span.
 */
interface SpanInterface
{
    public function setAttribute(string $key, string|int|float|bool $value): void;

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): void;

    public function recordException(Throwable $exception): void;

    public function setStatus(bool $ok, ?string $description = null): void;

    public function end(): void;
}
