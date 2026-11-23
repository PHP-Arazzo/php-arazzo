<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Support\Events\Dispatcher\NullEventDispatcher;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cohesive observability & determinism collaborators shared across sync and
 * async execution components.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class ObservabilityContext
{
    public EventDispatcherInterface $events;

    public LoggerInterface $logger;

    public TracerInterface $tracer;

    public ClockInterface $clock;

    public function __construct(
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
        ?TracerInterface $tracer = null,
        ?ClockInterface $clock = null,
    ) {
        $this->events = $events ?? new NullEventDispatcher();
        $this->logger = $logger ?? new NullLogger();
        $this->tracer = $tracer ?? new NullTracer();
        $this->clock = $clock ?? new SystemClock();
    }

    public function withEvents(EventDispatcherInterface $events): self
    {
        return new self($events, $this->logger, $this->tracer, $this->clock);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->events, $logger, $this->tracer, $this->clock);
    }

    public function withTracer(TracerInterface $tracer): self
    {
        return new self($this->events, $this->logger, $tracer, $this->clock);
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self($this->events, $this->logger, $this->tracer, $clock);
    }
}
