<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Plugins\OperationExecutor;

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
use Alama\Arazzo\Protocol\Soap\Exceptions\SoapExecutionException;
use Alama\Arazzo\Protocol\Soap\Execution\SoapFaultMapper;
use Alama\Arazzo\Protocol\Soap\Execution\SoapRequestBuilder;
use Alama\Arazzo\Protocol\Soap\Transfer\SoapResponseTransfer;
use Alama\Arazzo\Protocol\Soap\Transfer\SoapResponseTransferMapper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Operation protocol plugin executing steps bound to a WSDL-described SOAP
 * operation (plan F4.2).
 *
 * Registered first (priority 50, below HttpStepExecutor's 100) so a WSDL step
 * is claimed here rather than falling through to the generic HTTP executor;
 * supports() only claims steps that resolve to a WSDL source.
 *
 * Outcome mapping (plan F4.2): SOAP faults (HTTP 200 with a fault envelope)
 * and transport failures become failed StepExecutionOutcomes carrying
 * `{'soap.faultcode', 'soap.faultstring'}` in the transfer meta; success
 * exposes the SoapResponseTransfer as `responseBody['transfer']`.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsOperationExecutorPlugin(OperationProtocol::Soap)]
final class SoapOperationExecutor implements OperationExecutorPluginInterface
{
    /**
     * Below HttpStepExecutor::PLUGIN_PRIORITY (100): the registry tries lower
     * priorities first, so WSDL steps land here before the generic executor.
     */
    public const PLUGIN_PRIORITY = 50;

    private LoggerInterface $logger;

    private TracerInterface $tracer;

    public function __construct(
        private OperationResolverInterface $operationResolver,
        private SoapRequestBuilder $requestBuilder,
        private HttpClientInterface $httpClient,
        private SoapResponseTransferMapper $responseMapper,
        private SoapFaultMapper $faultMapper,
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
        return 'soap';
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

        $span = $this->tracer->startSpan('arazzo.soap.request', [
            'arazzo.step.id' => $step->stepId,
        ]);

        try {
            $request = $this->requestBuilder->build($step, $context, $document, $resolved, $executionId);
            $request = $this->decorateRequest($request, $sourcePolicy);
            $response = $this->httpClient->sendRequest($request, $timeoutSeconds);
        } catch (ClientExceptionInterface $e) {
            $span->recordException($e);
            $span->setStatus(false, $e->getMessage());
            $span->end();
            $this->logger->warning('SOAP transport request failed.', [
                'step' => $step->stepId,
                'error' => $e->getMessage(),
            ]);

            // Transport-level failures become a synthetic 500 (HTTP precedent)
            // so failureActions apply identically to the sync adapter.
            return $this->faultMapper->map(
                new SoapResponseTransfer(status: 500, headers: [], rawBody: '', meta: ['error' => $e->getMessage()]),
                'transport',
            );
        }

        $transfer = $this->responseMapper->map($response);

        if ($transfer->hasFailure()) {
            $category = $transfer->failureCategory() ?? 'transport';
            $span->setStatus(false, $category);
            $span->end();
            $this->logger->warning('SOAP operation returned fault or transport error.', [
                'step' => $step->stepId,
                'category' => $category,
            ]);

            return $this->faultMapper->map($transfer, $category);
        }

        $statusCode = $transfer->httpStatusCode();
        $span->setAttribute('http.response.status_code', $statusCode);
        $span->setStatus(true);
        $span->end();

        $this->logger->debug('Executed SOAP operation.', [
            'step' => $step->stepId,
            'status' => $statusCode,
        ]);

        return StepExecutionOutcome::resolved(
            statusCode: $statusCode,
            outputs: [],
            responseBody: ['transfer' => $transfer],
            responseHeaders: $transfer->headers(),
            rawBody: $transfer->rawXmlBody(),
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
            throw SoapExecutionException::resolutionFailed($step->stepId, $e);
        }
    }
}
