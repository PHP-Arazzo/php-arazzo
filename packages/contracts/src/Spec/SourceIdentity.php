<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

final readonly class SourceIdentity
{
    public function __construct(
        public string $retrievalUri,
        public ?string $selfUri = null,
        public ?SourceDocument $document = null,
    ) {}

    public function hasSelf(): bool
    {
        return $this->selfUri !== null && $this->selfUri !== '';
    }

    public function baseUri(): string
    {
        return $this->selfUri ?? $this->retrievalUri;
    }
}
