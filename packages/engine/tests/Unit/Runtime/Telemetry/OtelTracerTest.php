<?php

declare(strict_types=1);

use Alama\Arazzo\Runtime\Telemetry\OtelSetup;
use Alama\Arazzo\Runtime\Telemetry\OtelTracer;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

beforeEach(function (): void {
    OtelSetup::reset();
});

afterEach(function (): void {
    OtelSetup::reset();
});

it('bridges TracerInterface spans, attributes, events, exceptions, and W3C propagation to OpenTelemetry', function (): void {
    $exporter = new InMemoryExporter();
    OtelSetup::initialize(exporterOverride: $exporter);

    $tracer = new OtelTracer();
    $span = $tracer->startSpan('arazzo.workflow.run', ['execution.id' => 'exec_otel_1', '' => 'ignored']);
    $span->setAttribute('', 'ignored');
    $span->setAttribute('workflow.id', 'wf_1');
    $span->addEvent('');
    $span->addEvent('step.started', ['step.id' => 's1', '' => 'ignored']);
    $span->recordException(new RuntimeException('step exploded'));
    $span->setStatus(false, 'step exploded');

    $carrier = [];
    $tracer->injectContext($carrier);
    $extracted = $tracer->extractContext($carrier + ['tracestate' => 'vendor=1']);
    $emptyExtracted = $tracer->extractContext([]);

    $span->end();

    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('arazzo.workflow.run')
        ->and($spans[0]->getAttributes()->get('execution.id'))->toBe('exec_otel_1')
        ->and($spans[0]->getAttributes()->get('workflow.id'))->toBe('wf_1')
        ->and($spans[0]->getEvents())->toHaveCount(2)
        ->and($carrier)->toHaveKey('traceparent')
        ->and($extracted)->toBe([
            'traceparent' => $carrier['traceparent'],
            'tracestate' => 'vendor=1',
        ])
        ->and($emptyExtracted)->toBeNull();
});
