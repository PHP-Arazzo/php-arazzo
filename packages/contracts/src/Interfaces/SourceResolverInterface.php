<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

interface SourceResolverInterface
{
    /**
     * Resolve and decode a source description into a SourceDocument.
     */
    public function resolve(SourceDescription $source, string $basePath): SourceDocument;
}
