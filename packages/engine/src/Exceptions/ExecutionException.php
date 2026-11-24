<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class ExecutionException extends EngineException
{
    public static function subWorkflowNotFound(string $workflowId): self
    {
        return new self(
            "Sub-workflow '{$workflowId}' not found in registry.",
            '/',
            'execution.subworkflow_not_found',
        );
    }

    public static function messageFactoryMissing(string $stepId): self
    {
        return new self(
            "AsyncAPI step '{$stepId}' cannot compile its message: no PSR-17 request/stream/URI factory was provided.",
            '/',
            'execution.message_factory_missing',
        );
    }

    public static function unresolvableChannelTarget(string $stepId, string $channelPath): self
    {
        return new self(
            "Send step '{$stepId}' has channelPath '{$channelPath}', which is not an absolute HTTP URI. "
            .'Use an absolute broker/webhook URL, or wire AsyncAPI server resolution in the executor.',
            '/',
            'execution.unresolvable_channel_target',
        );
    }

    public static function noExecutorRegistry(): self
    {
        return new self(
            'No operation executor registry configured; register a protocol executor plugin.',
            '/',
            'execution.no_executor_registry',
        );
    }

    public static function noSupportingExecutor(string $stepId): self
    {
        return new self(
            "No OperationExecutorPluginInterface supports step '{$stepId}'. Register a protocol executor plugin (e.g. HttpProtocol::create()->executor()).",
            '/',
            'execution.no_supporting_executor',
        );
    }

    public static function suspendedInSyncMode(string $stepId): self
    {
        return new self(
            "Step '{$stepId}' suspended, which the synchronous runner cannot resume.",
            '/',
            'execution.suspended_in_sync_mode',
        );
    }
}
