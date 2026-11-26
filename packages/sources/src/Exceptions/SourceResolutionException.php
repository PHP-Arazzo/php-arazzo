<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use RuntimeException;
use Throwable;

class SourceResolutionException extends RuntimeException
{
    public readonly string $selfUri;

    public readonly string $existingRetrievalUri;

    public readonly string $newRetrievalUri;

    /**
     * @param  list<string>  $conflictingUris
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string $selfUri = '',
        array $conflictingUris = [],
    ) {
        parent::__construct($message, $code, $previous);
        $this->selfUri = $selfUri;
        $this->existingRetrievalUri = $conflictingUris[0] ?? '';
        $this->newRetrievalUri = $conflictingUris[1] ?? '';
    }

    public static function duplicateSelfUri(
        string $selfUri,
        string $existingRetrievalUri,
        string $newRetrievalUri,
    ): self {
        return new self(
            message: "Duplicate \$self URI '{$selfUri}' claimed by '{$newRetrievalUri}'; already registered from '{$existingRetrievalUri}'.",
            code: 0,
            previous: null,
            selfUri: $selfUri,
            conflictingUris: [$existingRetrievalUri, $newRetrievalUri],
        );
    }
}
