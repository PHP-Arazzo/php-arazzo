<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

/**
 * Resolves local JSON-pointer `$ref` entries and merges path + operation OpenAPI parameters.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class OpenApiParameterNormalizer
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $pathItem
     * @param  array<string, mixed>  $operation
     * @return array{path: array<string, array<string, mixed>>, query: array<string, array<string, mixed>>, header: array<string, array<string, mixed>>, cookie: array<string, array<string, mixed>>}
     */
    public function resolveParameters(array $document, array $pathItem, array $operation): array
    {
        $merged = [];
        $this->collectScopeParameters($document, $pathItem, $merged);
        $this->collectScopeParameters($document, $operation, $merged);

        return $this->groupParameters($merged);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function resolveLocalRef(array $document, array $item): array
    {
        $ref = $item['$ref'] ?? null;
        if (!is_string($ref) || !str_starts_with($ref, '#/')) {
            return $item;
        }

        $target = $this->followPointer($document, substr($ref, 2));
        if ($target === null) {
            return $item;
        }

        $result = array_merge($target, $item);
        unset($result['$ref']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $scope
     * @param  array<string, array<string, mixed>>  $merged
     */
    private function collectScopeParameters(array $document, array $scope, array &$merged): void
    {
        $rawParams = $scope['parameters'] ?? null;
        if (!is_array($rawParams)) {
            return;
        }

        foreach ($rawParams as $rawParam) {
            if (!is_array($rawParam)) {
                continue;
            }
            /** @var array<string, mixed> $rawParam */
            $param = $this->resolveLocalRef($document, $rawParam);
            if (is_string($param['name'] ?? null) && is_string($param['in'] ?? null)) {
                $merged[$param['name'].'|'.$param['in']] = $param;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $merged
     * @return array{path: array<string, array<string, mixed>>, query: array<string, array<string, mixed>>, header: array<string, array<string, mixed>>, cookie: array<string, array<string, mixed>>}
     */
    private function groupParameters(array $merged): array
    {
        $grouped = [
            'path' => [],
            'query' => [],
            'header' => [],
            'cookie' => [],
        ];

        foreach ($merged as $param) {
            $in = $param['in'];
            $name = $param['name'];
            if (is_string($in) && is_string($name) && isset($grouped[$in])) {
                $grouped[$in][$name] = $param;
            }
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    private function followPointer(array $document, string $pointer): ?array
    {
        $current = $document;
        foreach (explode('/', $pointer) as $rawPart) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $rawPart);
            if (!is_array($current) || !isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        if (!is_array($current)) {
            return null;
        }

        /** @var array<string, mixed> $current */
        return $current;
    }
}
