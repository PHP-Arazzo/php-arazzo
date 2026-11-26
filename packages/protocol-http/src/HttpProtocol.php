<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\HttpRuntimeContext;
use Alama\Arazzo\Protocol\Http\Execution\RequestSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Public entry point for wiring HTTP/OpenAPI step execution and operation
 * resolution into a protocol-agnostic workflow runner.
 */
final readonly class HttpProtocol
{
    private OpenApiOperationResolver $operationResolver;

    private function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private SourcesInterface $sources,
        private HttpRuntimeContext $runtime,
    ) {
        $this->operationResolver = OpenApiOperationResolver::create($this->sources);
    }

    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?SourcesInterface $sources = null,
        ?EvaluationEngineInterface $evaluationEngine = null,
        ?ExpressionEngineInterface $expressionEngine = null,
    ): self {
        $resolvedClient = $httpClient ?? new Client();
        $resolvedFactory = $requestFactory ?? new HttpFactory();
        $resolvedSources = $sources ?? Sources::create()->withHttpClient($resolvedClient, $resolvedFactory);
        $resolvedExpr = $expressionEngine ?? ExpressionEngine::create();
        $resolvedEval = $evaluationEngine ?? new EvaluationEngine(expression: $resolvedExpr);
        $runtime = new HttpRuntimeContext($resolvedEval, $resolvedExpr);

        return new self($resolvedClient, $resolvedFactory, $resolvedSources, $runtime);
    }

    public function withPolicy(HttpPolicy $policy): self
    {
        return new self(
            $this->httpClient,
            $this->requestFactory,
            $this->sources,
            $this->runtime->withPolicy($policy),
        );
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self(
            $this->httpClient,
            $this->requestFactory,
            $this->sources->withLogger($logger),
            $this->runtime->withLogger($logger),
        );
    }

    public function withTracer(TracerInterface $tracer): self
    {
        return new self(
            $this->httpClient,
            $this->requestFactory,
            $this->sources->withTracer($tracer),
            $this->runtime->withTracer($tracer),
        );
    }

    public function executor(
        ?bool $strictValidation = null,
        ?IdempotencyKeyInjector $idempotencyInjector = null,
    ): OperationExecutorPluginInterface {
        return new HttpStepExecutor(
            openApiExecutor: new DefaultOpenApiExecutor(
                $this->httpClient,
                $this->requestFactory,
                $this->runtime->logger,
                $this->runtime->tracer,
            ),
            operationResolver: $this->operationResolver,
            evaluationEngine: $this->runtime->evaluationEngine,
            outputExtractor: $this->outputExtractor(),
            schemaValidator: new ResponseSchemaValidator($this->operationResolver),
            strictDefault: $strictValidation ?? $this->runtime->policy->strictValidation,
            injector: $idempotencyInjector ?? $this->runtime->policy->idempotencyInjector,
            requestValidator: new RequestSchemaValidator($this->operationResolver),
        );
    }

    public function resolver(): OperationResolverInterface
    {
        return $this->operationResolver;
    }

    public function outputExtractor(): OutputExtractorInterface
    {
        return new StepOutputExtractor(
            $this->operationResolver,
            $this->runtime->evaluationEngine,
            $this->runtime->expressionEngine,
        );
    }
}
