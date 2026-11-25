<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Cli;

use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;

/**
 * Terminal snapshot of a CLI run: the store status at drain end, plus
 * whether steps remain suspended (resume-worthy).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class CliRunResult
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $status,
        public readonly bool $suspended,
    ) {}

    public static function fromStore(string $executionId, ExecutionStoreInterface $store): self
    {
        $status = match (true) {
            $store instanceof CliExecutionStore,
            $store instanceof InMemoryExecutionStore => $store->statusOf($executionId),
            default => null,
        };

        return new self(
            executionId: $executionId,
            status: $status !== null ? $status->value : 'running',
            suspended: ($status === null || $status === ExecutionStatus::Running),
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
