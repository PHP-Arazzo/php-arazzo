<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Runner;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared immutable configuration bag for {@see Runner}
 * and {@see AsyncRunner}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class RunnerOptions
{
    /**
     * @param  list<OperationExecutorPluginInterface>  $executors
     * @param  list<OperationResolverInterface>  $operationResolvers
     */
    public function __construct(
        public array $executors = [],
        public array $operationResolvers = [],
        public ExecutionPolicy $policy = new ExecutionPolicy(),
        public ObservabilityContext $observability = new ObservabilityContext(),
    ) {}

    public function withExecutor(OperationExecutorPluginInterface $executor): self
    {
        return new self(
            [...$this->executors, $executor],
            $this->operationResolvers,
            $this->policy,
            $this->observability,
        );
    }

    public function withOperationResolver(OperationResolverInterface $resolver): self
    {
        return new self(
            $this->executors,
            [...$this->operationResolvers, $resolver],
            $this->policy,
            $this->observability,
        );
    }

    public function withPolicy(ExecutionPolicy $policy): self
    {
        return new self(
            $this->executors,
            $this->operationResolvers,
            $policy,
            $this->observability,
        );
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        return new self(
            $this->executors,
            $this->operationResolvers,
            $this->policy,
            $this->observability->withEvents($dispatcher),
        );
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            $this->executors,
            $this->operationResolvers,
            $this->policy,
            $this->observability->withLogger($logger),
        );
    }

    public function withTracer(TracerInterface $tracer): self
    {
        return new self(
            $this->executors,
            $this->operationResolvers,
            $this->policy,
            $this->observability->withTracer($tracer),
        );
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self(
            $this->executors,
            $this->operationResolvers,
            $this->policy,
            $this->observability->withClock($clock),
        );
    }
}
