<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class CustomTagsForbiddenException extends DecodeLimitException
{
    public function __construct(
        string $tag = '',
        string $codeId = 'document.yaml_limit',
        ?Throwable $previous = null,
    ) {
        $tagMsg = $tag !== '' ? " ({$tag})" : '';
        parent::__construct(
            message: "Custom YAML tags are forbidden by policy allowCustomTags{$tagMsg} [{$codeId}].",
            limit: 'allowCustomTags',
            codeId: $codeId,
            code: 0,
            previous: $previous,
        );
    }

    public static function forTag(string $tag): self
    {
        return new self($tag);
    }
}
