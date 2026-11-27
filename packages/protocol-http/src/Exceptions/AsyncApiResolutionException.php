<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApiResolutionException extends EngineException
{
    public static function channelNotFound(string $channelRef, string $sourceName): self
    {
        return new self(
            "Channel '{$channelRef}' not found in source '{$sourceName}'.",
            '/',
            'asyncapi.channel_not_found',
        );
    }

    public static function sourceNotFound(string $sourceName, string $stepId): self
    {
        return new self(
            "Source '{$sourceName}' not found in document for step '{$stepId}'.",
            '/',
            'asyncapi.source_not_found',
        );
    }

    public static function documentLoadFailed(string $sourceName, ?\Throwable $previous = null): self
    {
        $suffix = $previous !== null ? ': '.$previous->getMessage() : '.';

        return new self(
            "Could not load or parse AsyncAPI document for source '{$sourceName}'{$suffix}",
            '/',
            'asyncapi.load_failed',
            $previous,
        );
    }

    public static function unresolvableChannelTarget(string $stepId, string $channelPath): self
    {
        return new self(
            "Step '{$stepId}' has channelPath '{$channelPath}', which could not be resolved to a valid target URI.",
            '/',
            'asyncapi.unresolvable_channel_target',
        );
    }
}
