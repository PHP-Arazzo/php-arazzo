<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver\Interfaces;

use Alama\Arazzo\Contracts\Interfaces\SourceFetcherInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
interface SourceFetcher extends SourceFetcherInterface
{
    public function fetch(string $urlOrPath, string $basePath): string;
}
