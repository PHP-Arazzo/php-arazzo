<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Observability;

/**
 * Vendor-agnostic distributed tracing contract shared across I/O and
 * orchestration packages. OpenTelemetry or other backends bind via adapters.
 */
interface TracerInterface
{
    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function startSpan(string $name, array $attributes = []): SpanInterface;

    /**
     * Inject current trace context headers (e.g. W3C traceparent/tracestate) into an outbound carrier.
     *
     * @param  array<string, string>  $carrier
     */
    public function injectContext(array &$carrier): void;

    /**
     * Extract trace context headers from an inbound carrier.
     *
     * @param  array<string, string>  $carrier
     * @return array<string, string>|null
     */
    public function extractContext(array $carrier): ?array;
}
