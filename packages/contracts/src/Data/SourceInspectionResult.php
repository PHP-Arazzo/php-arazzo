<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

final readonly class SourceInspectionResult
{
    public function __construct(
        public SourceResolutionCollection $resolutions,
        public KnownOperations $knownOperations,
    ) {}
}
