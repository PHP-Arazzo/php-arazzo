<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer\Interfaces;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
interface AsyncApiNormalizerInterface
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, NormalizedOpenApiOperation> Indexed by channel identifier and channel address
     */
    public function normalizeChannels(array $document): array;
}
