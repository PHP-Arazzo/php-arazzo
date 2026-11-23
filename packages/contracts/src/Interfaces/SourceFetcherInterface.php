<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

interface SourceFetcherInterface
{
    /**
     * Fetch raw document content from a URL or filesystem path.
     */
    public function fetch(string $urlOrPath, string $basePath): string;
}
