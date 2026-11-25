<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use LogicException;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApiStepException extends LogicException
{
    public static function unsupportedSpecVersion(string $stepId): self
    {
        return new self("AsyncAPI step '{$stepId}' encountered under a 1.0.0 document; upgrade to arazzo: 1.1.0.");
    }

    public static function unsupportedAction(?string $action, string $stepId): self
    {
        return new self("Unsupported action '{$action}' for step '{$stepId}'.");
    }

    public static function missingCorrelationId(string $stepId): self
    {
        return new self("Step '{$stepId}' has action 'receive' but no correlationId expression.");
    }

    public static function missingReceiveChannelPath(string $stepId): self
    {
        return new self("Step '{$stepId}' has action 'receive' but no channelPath.");
    }

    public static function missingSendChannelPath(string $stepId): self
    {
        return new self("Send step '{$stepId}' has no channelPath.");
    }

    public static function unencodablePayload(string $stepId, Throwable $previous): self
    {
        return new self("Step '{$stepId}' produced an unencodable message payload: {$previous->getMessage()}", 0, $previous);
    }
}
