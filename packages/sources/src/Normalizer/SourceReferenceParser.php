<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use RuntimeException;

/**
 * Parses operation target references into source name and reference path/id.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SourceReferenceParser
{
    public static function isOpenApiStep(Step $step, ArazzoDocument $document): bool
    {
        $sourceName = self::resolveSourceName($step, $document);
        if ($sourceName === null) {
            return false;
        }

        foreach ($document->sourceDescriptions as $source) {
            if ($source->name === $sourceName) {
                return $source->type === SourceType::Openapi;
            }
        }

        return false;
    }

    public static function resolveSourceName(Step $step, ArazzoDocument $document): ?string
    {
        $opPath = $step->target->operationPath;
        if ($opPath !== null && $opPath !== '') {
            return self::extractFromUrlExpression($opPath);
        }

        $opId = $step->target->operationId;
        if ($opId !== null && $opId !== '') {
            return self::extractFromOperationId($opId, $document);
        }

        return null;
    }

    /**
     * @return array{sourceName: string, operationReference: string, isPath: bool}
     */
    public static function parseTarget(Step $step, ArazzoDocument $document): array
    {
        $opPath = $step->target->operationPath;
        if ($opPath !== null && $opPath !== '') {
            return self::parseFromOperationPath($opPath, $step->stepId);
        }

        $opId = $step->target->operationId;
        if ($opId !== null && $opId !== '') {
            return self::parseFromOperationId($opId, $step->stepId, $document);
        }

        throw new RuntimeException("Step '{$step->stepId}' must have either operationId or operationPath.");
    }

    /**
     * @return array{sourceName: string, operationReference: string, isPath: bool}
     */
    private static function parseFromOperationPath(string $opPath, string $stepId): array
    {
        $parts = explode('#', $opPath, 2);
        if (count($parts) !== 2 || !str_starts_with($parts[0], '{$sourceDescriptions.') || !str_ends_with($parts[0], '.url}')) {
            throw new RuntimeException("Invalid operationPath format in step '{$stepId}'. Expected {\$sourceDescriptions.NAME.url}#...");
        }

        return [
            'sourceName' => substr($parts[0], 21, -5),
            'operationReference' => $parts[1],
            'isPath' => true,
        ];
    }

    /**
     * @return array{sourceName: string, operationReference: string, isPath: bool}
     */
    private static function parseFromOperationId(string $opId, string $stepId, ArazzoDocument $document): array
    {
        if (str_contains($opId, '#')) {
            $parts = explode('#', $opId, 2);
            if (!str_starts_with($parts[0], '{$sourceDescriptions.') || !str_ends_with($parts[0], '.url}')) {
                throw new RuntimeException("Invalid operationId format in step '{$stepId}'. Expected {\$sourceDescriptions.NAME.url}#...");
            }

            return [
                'sourceName' => substr($parts[0], 21, -5),
                'operationReference' => $parts[1],
                'isPath' => false,
            ];
        }

        if (preg_match('/^\$sourceDescriptions\.([^.]+)\.(.+)$/', $opId, $m) === 1) {
            return [
                'sourceName' => $m[1],
                'operationReference' => $m[2],
                'isPath' => false,
            ];
        }

        $nonArazzo = array_filter($document->sourceDescriptions, fn (SourceDescription $s) => $s->type !== SourceType::Arazzo);
        if (count($nonArazzo) !== 1) {
            throw new RuntimeException("Plain operationId '{$opId}' used in step '{$stepId}' but there are multiple non-Arazzo sources. Please use source-qualified operationId.");
        }

        return [
            'sourceName' => reset($nonArazzo)->name,
            'operationReference' => $opId,
            'isPath' => false,
        ];
    }

    private static function extractFromUrlExpression(string $value): ?string
    {
        $parts = explode('#', $value, 2);
        if (count($parts) === 2 && str_starts_with($parts[0], '{$sourceDescriptions.') && str_ends_with($parts[0], '.url}')) {
            return substr($parts[0], 21, -5);
        }

        return null;
    }

    private static function extractFromOperationId(string $opId, ArazzoDocument $document): ?string
    {
        if (str_contains($opId, '#')) {
            return self::extractFromUrlExpression($opId);
        }

        if (preg_match('/^\$sourceDescriptions\.([^.]+)\.(.+)$/', $opId, $m) === 1) {
            return $m[1];
        }

        $nonArazzo = array_filter($document->sourceDescriptions, fn (SourceDescription $s) => $s->type !== SourceType::Arazzo);
        if (count($nonArazzo) === 1) {
            return reset($nonArazzo)->name;
        }

        return null;
    }
}
