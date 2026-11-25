<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runtime\Telemetry;

use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;

/**
 * OpenTelemetry adapter implementing the vendor-agnostic {@see TracerInterface}.
 *
 * Quarantines the OpenTelemetry API behind the contracts tracing seam so
 * execution and protocol packages remain vendor-agnostic.
 */
final readonly class OtelTracer implements TracerInterface
{
    public function __construct(
        private string $instrumentationName = 'alama.arazzo.runner',
        private TraceContextPropagator $propagator = new TraceContextPropagator(),
    ) {}

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $spanName = $name !== '' ? $name : 'arazzo.span';
        $builder = OtelSetup::getTracer($this->instrumentationName)->spanBuilder($spanName);

        foreach ($attributes as $key => $value) {
            if ($key !== '') {
                $builder->setAttribute($key, $value);
            }
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        return new OtelSpan($span, $scope);
    }

    public function injectContext(array &$carrier): void
    {
        /** @var array<string, mixed> $raw */
        $raw = $carrier;
        $this->propagator->inject($raw);

        foreach ($raw as $key => $value) {
            if (is_string($value)) {
                $carrier[$key] = $value;
            }
        }
    }

    public function extractContext(array $carrier): ?array
    {
        $traceparent = TraceContextPropagator::traceparentOf($carrier);
        if ($traceparent === null) {
            return null;
        }

        $extracted = ['traceparent' => $traceparent];
        if (isset($carrier['tracestate']) && $carrier['tracestate'] !== '') {
            $extracted['tracestate'] = $carrier['tracestate'];
        }

        return $extracted;
    }
}
