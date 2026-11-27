<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Internal immutable configuration bag carried by HttpProtocol across fluent rebinds.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class HttpRuntimeContext
{
    public HttpPolicy $policy;

    public LoggerInterface $logger;

    public TracerInterface $tracer;

    public OutboundRateLimiter $rateLimiter;

    public function __construct(
        public EvaluationEngineInterface $evaluationEngine,
        public ExpressionEngineInterface $expressionEngine,
        ?HttpPolicy $policy = null,
        ?LoggerInterface $logger = null,
        ?TracerInterface $tracer = null,
    ) {
        $this->policy = $policy ?? HttpPolicy::default();
        $this->logger = $logger ?? new NullLogger();
        $this->tracer = $tracer ?? new NullTracer();
        $this->rateLimiter = $this->policy->rateLimiter ?? new OutboundRateLimiter();
    }

    public function withPolicy(HttpPolicy $policy): self
    {
        return new self(
            evaluationEngine: $this->evaluationEngine,
            expressionEngine: $this->expressionEngine,
            policy: $policy,
            logger: $this->logger,
            tracer: $this->tracer,
        );
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            evaluationEngine: $this->evaluationEngine,
            expressionEngine: $this->expressionEngine,
            policy: $this->policy,
            logger: $logger,
            tracer: $this->tracer,
        );
    }

    public function withTracer(TracerInterface $tracer): self
    {
        return new self(
            evaluationEngine: $this->evaluationEngine,
            expressionEngine: $this->expressionEngine,
            policy: $this->policy,
            logger: $this->logger,
            tracer: $tracer,
        );
    }

    public function withRateLimiter(OutboundRateLimiter $rateLimiter): self
    {
        return $this->withPolicy($this->policy->withRateLimiter($rateLimiter));
    }
}
