<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

final class CriterionEvaluationException extends ArazzoException
{
    public static function invalidRegex(string $pattern): self
    {
        return new self("Invalid regular expression: {$pattern}", '', 'criterion.invalid_regex');
    }

    public static function regexRequiresContext(): self
    {
        return new self('A regex criterion requires a context.', '', 'criterion.regex_requires_context');
    }

    public static function nullContext(string $context): self
    {
        return new self("Criterion context '{$context}' resolved to null.", '', 'criterion.null_context');
    }
}
