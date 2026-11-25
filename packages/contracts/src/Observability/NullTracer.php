<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Observability;

/**
 * Zero-overhead default tracer used when no tracing backend is wired.
 */
final readonly class NullTracer implements TracerInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new NullSpan();
    }

    public function injectContext(array &$carrier): void {}

    public function extractContext(array $carrier): ?array
    {
        return null;
    }
}
