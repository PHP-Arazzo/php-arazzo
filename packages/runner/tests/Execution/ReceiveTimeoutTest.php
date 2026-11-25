<?php

declare(strict_types=1);

namespace Tests\Execution;

use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Runner\Async\CorrelationResumer;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use DateTimeImmutable;

require_once __DIR__.'/../Async/CorrelationResumerTest.php';

function expiryResumer(bool $expired): array
{
    [$definitions, $definitionId] = resumerDocument();

    $store = new ResumerMockStore();
    $store->toReturn = new PendingCorrelation(
        'corr_x',
        'exec_1',
        'wait-for-ride',
        'channels/rides/created',
        new DateTimeImmutable($expired ? '-10 minutes' : '+10 minutes'),
    );
    $store->state = [
        'definitionId' => $definitionId,
        'workflowId' => 'wf_1',
        'inputs' => [],
        'steps' => [],
        'components' => [],
    ];

    $logger = new ResumerSpyLogger();
    $outcomeHandler = new RecordingStepOutcomeHandler();

    $outputExtractor = \Mockery::mock(OutputExtractorInterface::class);
    $outputExtractor->shouldReceive('extractOutputs')->andReturn([]);
    $engine = \Mockery::mock(EvaluationEngineInterface::class);
    $engine->shouldReceive('evaluateSuccessCriteria')->andReturn(true);

    $resumer = new CorrelationResumer(
        new AsyncPorts($store, $definitions, new SyncQueueDriver(), new ResumerMockLockManager(), $outputExtractor),
        $engine,
        $outcomeHandler,
        new ObservabilityContext(logger: $logger),
    );

    return [$resumer, $store, $logger, $outcomeHandler];
}

it('routes an expired receive correlation through the failure path', function (): void {
    [$resumer, $store, $logger, $outcomeHandler] = expiryResumer(expired: true);

    $resumer->resume('corr_x', ['statusCode' => 200, 'body' => ['late' => true]]);

    expect($logger->records[0]['message'] ?? null)->toContain('expired')
        ->and($outcomeHandler->handled)->toHaveCount(1)
        ->and($outcomeHandler->handled[0]['criteriaMet'])->toBeFalse()
        ->and($outcomeHandler->handled[0]['context']->getSteps()['wait-for-ride']['response']['statusCode'])->toBe(504)
        ->and($store->consumed)->toBe(['corr_x']);
});

it('does not treat a live correlation as expired', function (): void {
    [$resumer, $store, $logger, $outcomeHandler] = expiryResumer(expired: false);

    $resumer->resume('corr_x', ['statusCode' => 200, 'body' => ['ok' => true]]);

    expect($logger->records[0]['message'] ?? null)->toContain('resumed')
        ->and($outcomeHandler->handled)->toHaveCount(1)
        ->and($outcomeHandler->handled[0]['criteriaMet'])->toBeTrue()
        ->and($outcomeHandler->handled[0]['context']->getSteps()['wait-for-ride']['response']['statusCode'])->toBe(200)
        ->and($store->consumed)->toBe(['corr_x']);
});
