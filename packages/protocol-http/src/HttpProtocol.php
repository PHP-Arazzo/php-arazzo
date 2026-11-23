<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http;

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Public entry point for wiring HTTP/OpenAPI step execution and operation
 * resolution into a protocol-agnostic workflow runner.
 */
final readonly class HttpProtocol
{
    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private SourcesInterface $sources;

    private EvaluationEngineInterface $evaluationEngine;

    private ExpressionEngineInterface $expressionEngine;

    private OpenApiOperationResolver $operationResolver;

    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?SourcesInterface $sources = null,
        ?EvaluationEngineInterface $evaluationEngine = null,
        ?ExpressionEngineInterface $expressionEngine = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
        $this->requestFactory = $requestFactory ?? new HttpFactory();
        $this->sources = $sources ?? Sources::create()->withHttpClient($this->httpClient, $this->requestFactory);
        $this->expressionEngine = $expressionEngine ?? ExpressionEngine::create();
        $this->evaluationEngine = $evaluationEngine ?? new EvaluationEngine(expression: $this->expressionEngine);
        $this->operationResolver = OpenApiOperationResolver::create($this->sources);
    }

    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?SourcesInterface $sources = null,
        ?EvaluationEngineInterface $evaluationEngine = null,
        ?ExpressionEngineInterface $expressionEngine = null,
    ): self {
        return new self($httpClient, $requestFactory, $sources, $evaluationEngine, $expressionEngine);
    }

    public function executor(bool $strictValidation = true, ?IdempotencyKeyInjector $idempotencyInjector = null): OperationExecutorPluginInterface
    {
        return new HttpStepExecutor(
            openApiExecutor: new DefaultOpenApiExecutor($this->httpClient, $this->requestFactory),
            operationResolver: $this->operationResolver,
            evaluationEngine: $this->evaluationEngine,
            outputExtractor: $this->outputExtractor(),
            schemaValidator: new ResponseSchemaValidator($this->operationResolver),
            strictValidationDefault: $strictValidation,
            injector: $idempotencyInjector,
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
            $this->evaluationEngine,
            $this->expressionEngine,
        );
    }
}
