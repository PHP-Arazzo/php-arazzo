<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Async\CorrelationResumer;
use Alama\Arazzo\Runner\Async\StepOutcomeHandler;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Psr\Log\AbstractLogger;

require_once __DIR__.'/../Execution/ResumerTestHelpers.php';

class RecordingStepOutcomeHandler extends StepOutcomeHandler
{
    /** @var list<array{executionId: string, stepId: string, criteriaMet: bool, suspended: bool, context: WorkflowContext}> */
    public array $handled = [];

    public function __construct() {}

    public function handle(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
        bool $criteriaMet,
        bool $suspended = false,
    ): void {
        $this->handled[] = [
            'executionId' => $executionId,
            'stepId' => $step->stepId,
            'criteriaMet' => $criteriaMet,
            'suspended' => $suspended,
            'context' => $context,
        ];
    }
}

class ResumerMockStore implements ExecutionStoreInterface
{
    public ?PendingCorrelation $toReturn = null;

    /** @var array<string, mixed>|null */
    public ?array $state = null;

    /** @var array<string, array<string, mixed>> */
    public array $saved = [];

    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $consumed = [];

    public function saveState(string $executionId, array $state, ?int $ttlSeconds = null): void
    {
        $this->saved[$executionId] = $state;
    }

    public function loadState(string $executionId): ?array
    {
        return $this->state;
    }

    public function deleteState(string $executionId): void {}

    public function markStarted(string $executionId, string $definitionId, string $workflowId): void {}

    public function markCompleted(string $executionId, ExecutionStatus $status): void {}

    public function saveCorrelation(string $correlationId, string $executionId, string $stepId, string $channelPath, ?int $timeoutSeconds = null): void
    {
        $this->created[] = $correlationId;
    }

    public function findCorrelation(string $correlationId): ?PendingCorrelation
    {
        return $this->toReturn;
    }

    public function consumeCorrelation(string $correlationId): void
    {
        $this->consumed[] = $correlationId;
    }

    public function hasPendingCorrelations(string $executionId): bool
    {
        return false;
    }
}

class RecordingDefinitionStore implements DefinitionStoreInterface
{
    public function __construct(private ?ArazzoDocument $document = null) {}

    public function save(ArazzoDocument $document, ?string $definitionId = null): string
    {
        return $definitionId ?? 'test-def';
    }

    public function find(string $definitionId): ?ArazzoDocument
    {
        return $this->document;
    }
}

class ResumerSpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

class ResumerMockLockManager implements LockManagerInterface
{
    /** @var list<string> */
    public array $acquired = [];

    public function acquire(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $this->acquired[] = $key;

        return $callback();
    }

    public function tryAcquire(string $key, int $ttlSeconds): bool
    {
        return true;
    }

    public function release(string $key): void {}
}

function makeResumer(
    ResumerMockStore $store,
    DefinitionStoreInterface $definitions,
    OutputExtractorInterface $extractor,
    EvaluationEngineInterface $engine,
    RecordingStepOutcomeHandler $outcomeHandler,
    ResumerSpyLogger $logger,
): CorrelationResumer {
    return new CorrelationResumer(
        new AsyncPorts($store, $definitions, new SyncQueueDriver(), new ResumerMockLockManager(), $extractor),
        $engine,
        $outcomeHandler,
        new ObservabilityContext(logger: $logger),
    );
}

it('does nothing when the correlation is not found', function (): void {
    $store = new ResumerMockStore();
    $logger = new ResumerSpyLogger();
    $outcomeHandler = new RecordingStepOutcomeHandler();

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore(),
        \Mockery::mock(OutputExtractorInterface::class),
        \Mockery::mock(EvaluationEngineInterface::class),
        $outcomeHandler,
        $logger,
    );

    $resumer->resume('missing', ['body' => ['x' => 1]]);

    expect($outcomeHandler->handled)->toBe([])
        ->and($store->consumed)->toBe([])
        ->and($logger->records)->toBe([]);
});

it('logs and does nothing when persisted state is missing', function (): void {
    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-for-ride', 'channels/rides/created');

    $logger = new ResumerSpyLogger();
    $outcomeHandler = new RecordingStepOutcomeHandler();

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore(),
        \Mockery::mock(OutputExtractorInterface::class),
        \Mockery::mock(EvaluationEngineInterface::class),
        $outcomeHandler,
        $logger,
    );

    $resumer->resume('corr_1', ['body' => ['x' => 1]]);

    expect($outcomeHandler->handled)->toBe([])
        ->and($logger->records[0]['message'])->toContain('Execution state missing')
        ->and($logger->records[0]['context']['correlationId'])->toBe('corr_1');
});

it('reports a missing definition when the persisted definitionId is not scalar', function (): void {
    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-for-ride', 'channels/rides/created');
    $store->state = ['definitionId' => ['not', 'a', 'string']];

    $logger = new ResumerSpyLogger();
    $outcomeHandler = new RecordingStepOutcomeHandler();

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore(),
        \Mockery::mock(OutputExtractorInterface::class),
        \Mockery::mock(EvaluationEngineInterface::class),
        $outcomeHandler,
        $logger,
    );

    $resumer->resume('corr_1', ['body' => ['x' => 1]]);

    expect($outcomeHandler->handled)->toBe([])
        ->and($logger->records[0]['message'])->toContain('Definition missing')
        ->and($logger->records[0]['context']['definitionId'])->toBe(['not', 'a', 'string']);
});

it('reports a missing workflow when the persisted workflowId is not scalar', function (): void {
    [$definitions, $definitionId] = resumerDocument();
    $document = $definitions->find($definitionId);

    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-for-ride', 'channels/rides/created');
    $store->state = ['definitionId' => $definitionId, 'workflowId' => ['not', 'a', 'string']];

    $logger = new ResumerSpyLogger();
    $outcomeHandler = new RecordingStepOutcomeHandler();

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore($document),
        \Mockery::mock(OutputExtractorInterface::class),
        \Mockery::mock(EvaluationEngineInterface::class),
        $outcomeHandler,
        $logger,
    );

    $resumer->resume('corr_1', ['body' => ['x' => 1]]);

    expect($outcomeHandler->handled)->toBe([])
        ->and($logger->records[0]['message'])->toContain('Workflow missing')
        ->and($logger->records[0]['context']['workflowId'])->toBe(['not', 'a', 'string']);
});

it('falls back to a 200 status when the resumption payload carries a non-int statusCode', function (): void {
    [$definitions, $definitionId] = resumerDocument();
    $document = $definitions->find($definitionId);

    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-for-ride', 'channels/rides/created');
    $store->state = ['definitionId' => $definitionId, 'workflowId' => 'wf_1'];

    $outcomeHandler = new RecordingStepOutcomeHandler();

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore($document),
        \Mockery::mock(OutputExtractorInterface::class)->shouldReceive('extractOutputs')->andReturn([])->getMock(),
        \Mockery::mock(EvaluationEngineInterface::class)->shouldReceive('evaluateSuccessCriteria')->andReturn(true)->getMock(),
        $outcomeHandler,
        new ResumerSpyLogger(),
    );

    $response = ['statusCode' => 'not-an-int', 'body' => ['x' => 1]];
    $resumer->resume('corr_1', $response);

    expect($store->saved['exec_1']['steps']['wait-for-ride']['statusCode'])->toBe(200)
        ->and($store->saved['exec_1']['steps']['wait-for-ride']['response'])->toBe($response)
        ->and($outcomeHandler->handled)->toHaveCount(1);
});

it('marks a resumed receive step as having received a message', function (): void {
    [$definitions, $definitionId] = resumerDocument();
    $document = $definitions->find($definitionId);

    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation('corr_1', 'exec_1', 'wait-for-ride', 'channels/rides/created');
    $store->state = ['definitionId' => $definitionId, 'workflowId' => 'wf_1'];

    $resumer = makeResumer(
        $store,
        new RecordingDefinitionStore($document),
        \Mockery::mock(OutputExtractorInterface::class)->shouldReceive('extractOutputs')->andReturn([])->getMock(),
        \Mockery::mock(EvaluationEngineInterface::class)->shouldReceive('evaluateSuccessCriteria')->andReturn(true)->getMock(),
        new RecordingStepOutcomeHandler(),
        new ResumerSpyLogger(),
    );

    $resumer->resume('corr_1', ['body' => ['x' => 1]]);

    expect($store->saved['exec_1']['steps']['wait-for-ride']['messageReceived'])->toBeTrue();
});
