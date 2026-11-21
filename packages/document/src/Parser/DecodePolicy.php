<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

final readonly class DecodePolicy
{
    public const int DEFAULT_MAX_BYTES = 5_242_880; // 5 MiB

    public const int DEFAULT_MAX_DEPTH = 64;

    public const int DEFAULT_MAX_NODES = 100_000;

    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxNodes = self::DEFAULT_MAX_NODES,
        public YamlPolicy $yaml = new YamlPolicy(),
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withMaxBytes(int $maxBytes): self
    {
        return new self(
            maxBytes: $maxBytes,
            maxDepth: $this->maxDepth,
            maxNodes: $this->maxNodes,
            yaml: $this->yaml,
        );
    }

    public function withMaxDepth(int $maxDepth): self
    {
        return new self(
            maxBytes: $this->maxBytes,
            maxDepth: $maxDepth,
            maxNodes: $this->maxNodes,
            yaml: $this->yaml,
        );
    }

    public function withMaxNodes(int $maxNodes): self
    {
        return new self(
            maxBytes: $this->maxBytes,
            maxDepth: $this->maxDepth,
            maxNodes: $maxNodes,
            yaml: $this->yaml,
        );
    }

    public function withYamlPolicy(YamlPolicy $yaml): self
    {
        return new self(
            maxBytes: $this->maxBytes,
            maxDepth: $this->maxDepth,
            maxNodes: $this->maxNodes,
            yaml: $yaml,
        );
    }
}
