<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class InvalidSourceReferenceException extends SourceResolutionException
{
    public static function missingOperationTarget(string $stepId): self
    {
        return new self("Step '{$stepId}' must have either operationId or operationPath.");
    }

    public static function invalidOperationPath(string $stepId): self
    {
        return new self("Invalid operationPath format in step '{$stepId}'. Expected {\$sourceDescriptions.NAME.url}#...");
    }

    public static function invalidOperationId(string $stepId): self
    {
        return new self("Invalid operationId format in step '{$stepId}'. Expected {\$sourceDescriptions.NAME.url}#...");
    }

    public static function ambiguousPlainOperationId(string $opId, string $stepId): self
    {
        return new self("Plain operationId '{$opId}' used in step '{$stepId}' but there are multiple non-Arazzo sources. Please use source-qualified operationId.");
    }
}
