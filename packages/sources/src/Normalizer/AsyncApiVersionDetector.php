<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApiVersionDetector
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function detect(array $document): string
    {
        $version = $document['asyncapi'] ?? '';
        if (!is_string($version) || $version === '') {
            return '3.0';
        }

        if (str_starts_with($version, '2.')) {
            return '2.0';
        }

        return '3.0';
    }
}
