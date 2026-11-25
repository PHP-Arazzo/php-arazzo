<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\Events\CorrelationResumedEvent;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Defaults\EvaluationOutputExtractor;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class CorrelationResumer
{
    private ExecutionStoreInterface $store;

    private DefinitionStoreInterface $definitions;

    private LockManagerInterface $lockManager;

    private OutputExtractorInterface $outputExtractor;

    private EventDispatcherInterface $events;

    private LoggerInterface $logger;

    private TracerInterface $tracer;

    private ClockInterface $clock;

    public function __construct(
        AsyncPorts $ports,
        private EvaluationEngineInterface $evaluationEngine,
        private StepOutcomeHandler $outcomeHandler,
        ObservabilityContext $observability = new ObservabilityContext(),
    ) {
        $this->store = $ports->store;
        $this->definitions = $ports->definitions;
        $this->lockManager = $ports->lockManager;
        $this->outputExtractor = $ports->outputExtractor ?? new EvaluationOutputExtractor($evaluationEngine);
        $this->events = $observability->events;
        $this->logger = $observability->logger;
        $this->tracer = $observability->tracer;
        $this->clock = $observability->clock;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function resume(string $correlationId, array $response): void
    {
        $correlation = $this->store->findCorrelation($correlationId);
        if ($correlation === null) {
            return;
        }

        $executionId = $correlation->executionId;
        $span = $this->tracer->startSpan('arazzo.correlation.resume', [
            'execution.id' => $executionId,
            'correlation.id' => $correlationId,
            'step.id' => $correlation->stepId,
        ]);

        try {
            $this->lockManager->acquire(
                "execution_lock_{$executionId}",
                30,
                function () use ($correlationId, $correlation, $response, $executionId): void {
                    $this->resumeLocked($correlationId, $correlation, $response, $executionId);
                },
            );
            $span->setStatus(true);
        } catch (Throwable $t) {
            $span->recordException($t);
            $span->setStatus(false, $t->getMessage());
            throw $t;
        } finally {
            $span->end();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function resumeLocked(string $correlationId, PendingCorrelation $correlation, array $response, string $executionId): void
    {
        $persisted = $this->store->loadState($executionId);
        if ($persisted === null) {
            $this->logger->warning('Execution state missing on correlation resume.', [
                'executionId' => $executionId,
                'correlationId' => $correlationId,
            ]);

            return;
        }

        $resolved = $this->resolveTarget($persisted, $correlation, $executionId);
        if ($resolved === null) {
            return;
        }

        [$document, $workflow, $step] = $resolved;

        if ($correlation->isExpiredAt($this->clock->now())) {
            $this->handleExpired($correlationId, $correlation, $resolved, $persisted, $executionId);

            return;
        }

        $context = WorkflowContext::fromPersisted($persisted, $executionId);
        $statusCode = self::httpStatus($response['statusCode'] ?? null);
        $body = $response['body'] ?? null;

        $contextWithResponse = $context->withStepResponse($step->stepId, [
            'statusCode' => $statusCode,
            'body' => $body,
        ]);
        $outputs = $this->outputExtractor->extractOutputs($step, $contextWithResponse, $document);

        $contextWithResult = $context->withStepResult($step->stepId, [
            'statusCode' => $statusCode,
            'response' => $response,
            'outputs' => $outputs,
            'messageReceived' => true,
        ]);

        $this->store->consumeCorrelation($correlationId);

        $this->events->dispatch(new CorrelationResumedEvent(
            $executionId,
            $workflow->workflowId,
            $step->stepId,
            $correlationId,
            $this->clock->now(),
        ));

        $criteriaMet = $this->evaluationEngine->evaluateSuccessCriteria($step, $contextWithResult, $document);

        $this->store->saveState($executionId, $contextWithResult->toArray());

        $this->logger->info('Step resumed from correlation.', [
            'executionId' => $executionId,
            'stepId' => $step->stepId,
            'correlationId' => $correlationId,
        ]);

        $this->outcomeHandler->handle($document, $workflow, $step, $contextWithResult, $executionId, $criteriaMet);
    }

    /**
     * @param  array<string, mixed>  $persisted
     * @return array{0: ArazzoDocument, 1: Workflow, 2: Step}|null
     */
    private function resolveTarget(array $persisted, PendingCorrelation $correlation, string $executionId): ?array
    {
        $definitionId = $persisted['definitionId'] ?? null;
        $workflowId = $persisted['workflowId'] ?? null;

        $document = is_scalar($definitionId) ? $this->definitions->find((string) $definitionId) : null;
        if ($document === null) {
            $this->logger->warning('Definition missing on correlation resume.', [
                'executionId' => $executionId,
                'definitionId' => $definitionId,
            ]);

            return null;
        }

        $workflow = is_scalar($workflowId) ? $document->findWorkflow((string) $workflowId) : null;
        if ($workflow === null) {
            $this->logger->warning('Workflow missing on correlation resume.', [
                'executionId' => $executionId,
                'workflowId' => $workflowId,
            ]);

            return null;
        }

        $step = $workflow->findStep($correlation->stepId);
        if ($step === null) {
            $this->logger->warning('Step missing on correlation resume.', [
                'executionId' => $executionId,
                'stepId' => $correlation->stepId,
            ]);

            return null;
        }

        return [$document, $workflow, $step];
    }

    /**
     * @param  array{0: ArazzoDocument, 1: Workflow, 2: Step}  $resolved
     * @param  array<string, mixed>  $persisted
     */
    private function handleExpired(
        string $correlationId,
        PendingCorrelation $correlation,
        array $resolved,
        array $persisted,
        string $executionId,
    ): void {
        [$document, $workflow, $step] = $resolved;
        $this->store->consumeCorrelation($correlationId);
        $this->logger->warning('Pending correlation expired.', [
            'executionId' => $executionId,
            'stepId' => $correlation->stepId,
            'correlationId' => $correlationId,
            'expiresAt' => $correlation->expiresAt?->format(DATE_ATOM),
        ]);

        $timedOut = WorkflowContext::fromPersisted($persisted, $executionId)
            ->withStepResponse($step->stepId, [
                'statusCode' => 504,
                'headers' => [],
                'body' => [],
            ]);

        $this->outcomeHandler->handle($document, $workflow, $step, $timedOut, $executionId, false);
    }

    private static function httpStatus(mixed $value): int
    {
        return is_int($value) ? $value : 200;
    }
}
