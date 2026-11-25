<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Plugins\OperationExecutor;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\HttpClientInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Protocol\Rpc\Exceptions\RpcExecutionException;
use Alama\Arazzo\Protocol\Rpc\Execution\RpcFailureMapper;
use Alama\Arazzo\Protocol\Rpc\Execution\RpcRequestBuilder;
use Alama\Arazzo\Protocol\Rpc\Protocol\RpcFieldBag;
use Alama\Arazzo\Protocol\Rpc\Transfer\RpcResponseTransfer;
use Alama\Arazzo\Protocol\Rpc\Transfer\RpcResponseTransferMapper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Operation protocol plugin executing steps bound to a protobuf-described RPC
 * method (plan F5.1).
 *
 * Registered at priority 50 (below HttpStepExecutor's 100) so an RPC step is
 * claimed here rather than falling through to the generic HTTP executor;
 * supports() only claims steps that resolve to a protobuf source.
 *
 * Outcome mapping (plan F5.1): RPC errors (grpc-status / X-Twirp-Error /
 * Connect-Error-Code) and transport failures become failed StepExecutionOutcomes
 * carrying the RPC error keys in the transfer meta; success exposes the
 * RpcResponseTransfer as `responseBody['transfer']`.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsOperationExecutorPlugin(OperationProtocol::Rpc)]
final class RpcOperationExecutor implements OperationExecutorPluginInterface
{
    /**
     * Below HttpStepExecutor::PLUGIN_PRIORITY (100): the registry tries lower
     * priorities first, so RPC steps land here before the generic executor.
     */
    public const PLUGIN_PRIORITY = 50;

    private LoggerInterface $logger;

    private TracerInterface $tracer;

    public function __construct(
        private OperationResolverInterface $operationResolver,
        private RpcRequestBuilder $requestBuilder,
        private HttpClientInterface $httpClient,
        private RpcResponseTransferMapper $responseMapper,
        private RpcFailureMapper $failureMapper,
    ) {
        $this->logger = new NullLogger();
        $this->tracer = new NullTracer();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    public function withTracer(TracerInterface $tracer): self
    {
        $clone = clone $this;
        $clone->tracer = $tracer;

        return $clone;
    }

    public function name(): string
    {
        return 'rpc';
    }

    public function priority(): int
    {
        return self::PLUGIN_PRIORITY;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return !$step->hasAction() && $this->operationResolver->supportsStep($step, $document);
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        $resolved = $this->resolve($step, $document);
        $sourcePolicy = $context->getActiveSourcePolicy();
        $timeoutSeconds = $step->flow->timeout ?? $sourcePolicy?->timeoutSeconds();

        $span = $this->tracer->startSpan('arazzo.rpc.request', [
            'arazzo.step.id' => $step->stepId,
        ]);

        try {
            $request = $this->requestBuilder->build($step, $context, $document, $resolved);
            $request = $this->decorateRequest($request, $sourcePolicy);
            $response = $this->httpClient->sendRequest($request, $timeoutSeconds);
        } catch (ClientExceptionInterface $e) {
            $span->recordException($e);
            $span->setStatus(false, $e->getMessage());
            $span->end();
            $this->logger->warning('RPC transport request failed.', [
                'step' => $step->stepId,
                'error' => $e->getMessage(),
            ]);

            // Transport-level failures become a synthetic 500 (HTTP precedent)
            // so failureActions apply identically to the sync adapter.
            return $this->failureMapper->map(
                new RpcResponseTransfer(status: 500, headers: [], rawBody: '', views: [], meta: ['error' => $e->getMessage()]),
                'transport',
            );
        }

        $protocol = $resolved->rpcProtocol;
        if ($protocol === null) {
            $span->setStatus(false, 'rpc_protocol_missing');
            $span->end();

            throw RpcExecutionException::protocolMissing($step->stepId);
        }

        $bags = $resolved->normalized->requestBodies['application/protobuf'] ?? null;
        $responseFields = RpcFieldBag::normalize(is_array($bags) ? ($bags['responseFields'] ?? []) : []);
        $transfer = $this->responseMapper->map($response, $protocol, $responseFields);

        if ($transfer->hasFailure()) {
            $category = $transfer->failureCategory() ?? 'transport';
            $span->setStatus(false, $category);
            $span->end();
            $this->logger->warning('RPC operation returned error.', [
                'step' => $step->stepId,
                'category' => $category,
            ]);

            return $this->failureMapper->map($transfer, $category);
        }

        $statusCode = $transfer->httpStatusCode();
        $span->setAttribute('http.response.status_code', $statusCode);
        $span->setStatus(true);
        $span->end();

        $this->logger->debug('Executed RPC operation.', [
            'step' => $step->stepId,
            'status' => $statusCode,
        ]);

        return StepExecutionOutcome::resolved(
            statusCode: $statusCode,
            outputs: [],
            responseBody: ['transfer' => $transfer],
            responseHeaders: $transfer->headers(),
            rawBody: $transfer->rawPayload(),
        );
    }

    private function decorateRequest(RequestInterface $request, ?SourcePolicy $sourcePolicy): RequestInterface
    {
        if ($sourcePolicy !== null) {
            foreach ($sourcePolicy->headers as $name => $value) {
                if (!$request->hasHeader($name)) {
                    $request = $request->withHeader($name, $value);
                }
            }
        }

        $carrier = [];
        $this->tracer->injectContext($carrier);
        foreach ($carrier as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        return $request;
    }

    private function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        try {
            return $this->operationResolver->resolve($step, $document);
        } catch (RuntimeException $e) {
            throw RpcExecutionException::resolutionFailed($step->stepId, $e);
        }
    }
}
