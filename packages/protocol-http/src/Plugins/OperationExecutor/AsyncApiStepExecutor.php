<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\PendingCorrelationRegistryInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Engine\Execution\ReusableParameterResolver;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiStepException;
use JsonException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsOperationExecutorPlugin(OperationProtocol::AsyncApi)]
final class AsyncApiStepExecutor implements OperationExecutorPluginInterface
{
    /**
     * Registry ordering (ascending, first supports() wins). AsyncAPI actions
     * are disjoint from plain HTTP steps, so the value only stabilises
     * iteration; see HttpStepExecutor::PLUGIN_PRIORITY.
     */
    public const PLUGIN_PRIORITY = 200;

    public function __construct(
        private PendingCorrelationRegistryInterface $pendingCorrelations,
        private EvaluationEngineInterface $evaluationEngine,
        private HttpClientInterface $httpClient,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        private ?UriFactoryInterface $uriFactory = null,
    ) {}

    public function name(): string
    {
        return 'asyncapi';
    }

    public function priority(): int
    {
        return self::PLUGIN_PRIORITY;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return $step->isAsyncAction();
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        if ($document->specVersion === SpecVersion::V1_0) {
            throw AsyncApiStepException::unsupportedSpecVersion($step->stepId);
        }

        if ($step->target->action === 'send') {
            $message = $this->compileMessage($step, $context, $document);
            $response = $this->httpClient->sendRequest($message, $step->flow->timeoutSeconds());

            return StepExecutionOutcome::resolved($response->getStatusCode(), [], []);
        }

        if (!$step->isReceiveAction()) {
            throw AsyncApiStepException::unsupportedAction($step->target->action, $step->stepId);
        }

        if ($step->target->correlationId === null) {
            throw AsyncApiStepException::missingCorrelationId($step->stepId);
        }
        if ($step->target->channelPath === null) {
            throw AsyncApiStepException::missingReceiveChannelPath($step->stepId);
        }

        $correlationId = self::stringify($this->evaluationEngine->evaluate($step->target->correlationId, new EvaluationContext($context, $step->stepId, $document)));

        $this->pendingCorrelations->create($correlationId, $executionId, $step->stepId, $step->target->channelPath, $step->flow->timeout);

        return StepExecutionOutcome::suspended();
    }

    /**
     * Compiles an outgoing message into a POST request targeting the step's
     * channelPath. Parameters become query arguments (or headers when declared
     * `in: header`); the requestBody payload, with its replacements applied,
     * becomes the JSON body.
     */
    private function compileMessage(Step $step, WorkflowContext $context, ArazzoDocument $document): RequestInterface
    {
        $factory = $this->requestFactory
            ?? throw ExecutionException::messageFactoryMissing($step->stepId);
        $streams = $this->streamFactory
            ?? throw ExecutionException::messageFactoryMissing($step->stepId);

        // resolveChannelUri() already raises messageFactoryMissing when the
        // URI factory is absent, so there is nothing to pre-check here.
        $uri = $this->resolveChannelUri($step);

        $evaluationContext = new EvaluationContext($context, $step->stepId, $document);

        $query = [];
        $headers = [];
        $parameters = new ReusableParameterResolver()->resolve($step->io->parameters, $document);
        foreach ($parameters as $parameter) {
            $value = $parameter->value instanceof Expression
                ? $this->evaluationEngine->evaluate($parameter->value, $evaluationContext)
                : $parameter->value;

            if ($parameter->in?->value === 'header') {
                $headers[$parameter->name] = self::stringify($value);

                continue;
            }

            $query[$parameter->name] = $value;
        }

        $payload = $this->buildPayload($step, $evaluationContext);

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw AsyncApiStepException::unencodablePayload($step->stepId, $e);
        }

        // Merge step parameters over any query arguments embedded in the channel URI.
        $existing = [];
        parse_str($uri->getQuery(), $existing);

        $target = $uri->withQuery(http_build_query(array_merge($existing, $query)));

        $request = $factory
            ->createRequest('POST', $target)
            ->withHeader('Content-Type', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withBody($streams->createStream($encoded));
    }

    private function resolveChannelUri(Step $step): UriInterface
    {
        $channelPath = $step->target->channelPath
            ?? throw AsyncApiStepException::missingSendChannelPath($step->stepId);

        if (preg_match('#^https?://#i', $channelPath) !== 1) {
            throw ExecutionException::unresolvableChannelTarget($step->stepId, $channelPath);
        }

        return ($this->uriFactory ?? throw ExecutionException::messageFactoryMissing($step->stepId))
            ->createUri($channelPath);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function buildPayload(Step $step, EvaluationContext $context): array
    {
        $requestBody = $step->io->requestBody;

        return $requestBody !== null && is_array($requestBody->payload)
            ? $this->evaluationEngine->replacePayload(
                $step,
                $requestBody->payload,
                fn (PayloadReplacement $replacement) => $replacement->value instanceof Expression
                    ? $this->evaluationEngine->evaluate($replacement->value, $context)
                    : $replacement->value,
            )
            : [];
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
