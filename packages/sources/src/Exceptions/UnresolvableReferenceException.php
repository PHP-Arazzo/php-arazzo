<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use Throwable;

class UnresolvableReferenceException extends SourceResolutionException
{
    public function __construct(
        string $message,
        public readonly string $sourceName = '',
        public readonly string $reference = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function circularReference(string $sourceName): self
    {
        return new self(
            "Circular reference detected when resolving source '{$sourceName}'",
            $sourceName,
        );
    }
}
