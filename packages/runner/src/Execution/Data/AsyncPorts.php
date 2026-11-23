<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\ExecutionStoreInterface;

/**
 * Runtime port collaborators for durable/async execution.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class AsyncPorts
{
    public function __construct(
        public ExecutionStoreInterface $store,
        public DefinitionStoreInterface $definitions,
        public QueueDriverInterface $queueDriver,
        public LockManagerInterface $lockManager,
        public ?OutputExtractorInterface $outputExtractor = null,
    ) {}

    public function withStore(ExecutionStoreInterface $store): self
    {
        return new self($store, $this->definitions, $this->queueDriver, $this->lockManager, $this->outputExtractor);
    }

    public function withDefinitions(DefinitionStoreInterface $definitions): self
    {
        return new self($this->store, $definitions, $this->queueDriver, $this->lockManager, $this->outputExtractor);
    }

    public function withQueueDriver(QueueDriverInterface $queueDriver): self
    {
        return new self($this->store, $this->definitions, $queueDriver, $this->lockManager, $this->outputExtractor);
    }

    public function withLockManager(LockManagerInterface $lockManager): self
    {
        return new self($this->store, $this->definitions, $this->queueDriver, $lockManager, $this->outputExtractor);
    }

    public function withOutputExtractor(OutputExtractorInterface $outputExtractor): self
    {
        return new self($this->store, $this->definitions, $this->queueDriver, $this->lockManager, $outputExtractor);
    }
}
