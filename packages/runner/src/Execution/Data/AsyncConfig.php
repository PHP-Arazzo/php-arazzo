<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\Execution\Defaults\InMemoryLockManager;
use Alama\Arazzo\Runner\Queue\SyncQueueDriver;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;
use Alama\Arazzo\Runner\Store\InMemoryExecutionStore;

/**
 * Full immutable configuration bag for {@see AsyncRunner}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class AsyncConfig
{
    public function __construct(
        public AsyncPorts $ports,
        public RunnerOptions $options = new RunnerOptions(),
    ) {}

    public static function defaults(): self
    {
        return new self(
            ports: new AsyncPorts(
                store: new InMemoryExecutionStore(),
                definitions: new InMemoryDefinitionStore(),
                queueDriver: new SyncQueueDriver(),
                lockManager: new InMemoryLockManager(),
            ),
        );
    }

    public function withPorts(AsyncPorts $ports): self
    {
        return new self($ports, $this->options);
    }

    public function withOptions(RunnerOptions $options): self
    {
        return new self($this->ports, $options);
    }
}
