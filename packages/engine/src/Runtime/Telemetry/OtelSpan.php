<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runtime\Telemetry;

use Alama\Arazzo\Contracts\Observability\SpanInterface;
use OpenTelemetry\API\Trace\SpanInterface as OtelSpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * OpenTelemetry adapter implementing the vendor-agnostic {@see SpanInterface}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class OtelSpan implements SpanInterface
{
    public function __construct(
        private OtelSpanInterface $span,
        private ScopeInterface $scope,
    ) {}

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        if ($key === '') {
            return;
        }

        $this->span->setAttribute($key, $value);
    }

    public function addEvent(string $name, array $attributes = []): void
    {
        if ($name === '') {
            return;
        }

        $nonEmpty = [];
        foreach ($attributes as $key => $value) {
            if ($key !== '') {
                $nonEmpty[$key] = $value;
            }
        }

        $this->span->addEvent($name, $nonEmpty);
    }

    public function recordException(Throwable $exception): void
    {
        $this->span->recordException($exception);
    }

    public function setStatus(bool $ok, ?string $description = null): void
    {
        $this->span->setStatus(
            $ok ? StatusCode::STATUS_OK : StatusCode::STATUS_ERROR,
            $description,
        );
    }

    public function end(): void
    {
        $this->scope->detach();
        $this->span->end();
    }
}
