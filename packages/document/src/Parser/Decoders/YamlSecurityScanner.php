<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Decoders;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\CustomTagsForbiddenException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxAliasesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\ObjectDeserializationForbiddenException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class YamlSecurityScanner
{
    private readonly YamlNodeRangeScanner $nodeScanner;

    public function __construct(?YamlNodeRangeScanner $nodeScanner = null)
    {
        $this->nodeScanner = $nodeScanner ?? new YamlNodeRangeScanner();
    }

    /**
     * Pre-scans YAML text for alias counts and anchor fan-out bombs before parsing.
     */
    public function scanAliases(string $source, DecodePolicy $policy): void
    {
        $clean = $this->stripStringsAndComments($source);
        $maxAliases = $policy->yaml->maxAliases;

        $rawAliases = $this->extractRawAliases($clean, $maxAliases);
        if (empty($rawAliases)) {
            return;
        }

        [$anchorRanges, $anchorAliases] = $this->collectAnchorRanges($clean);
        if (empty($anchorRanges)) {
            return;
        }

        $topLevelAliases = $this->extractTopLevelAliases($clean, $anchorRanges);
        $this->assertNoBomb($anchorAliases, $topLevelAliases, $maxAliases);
    }

    /**
     * Pre-scans YAML text for custom tags and object deserialization attempts.
     */
    public function scanTags(string $source, DecodePolicy $policy): void
    {
        $clean = $this->stripStringsAndComments($source);

        if (!$policy->yaml->allowObjectDeserialization && preg_match('/!php\/(?:object|const)/i', $clean)) {
            throw new ObjectDeserializationForbiddenException();
        }

        if (!$policy->yaml->allowCustomTags) {
            $this->assertNoCustomTags($clean);
        }
    }

    private function stripStringsAndComments(string $source): string
    {
        $clean = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:[^\']|\'\')*\'/', '""', $source) ?? $source;

        return preg_replace('/#[^\r\n]*/', '', $clean) ?? $clean;
    }

    /**
     * @return list<string>
     */
    private function extractRawAliases(string $clean, int $maxAliases): array
    {
        preg_match_all('/(?<=[^a-zA-Z0-9_\-]|^)\*([a-zA-Z0-9_\-]+)/', $clean, $matches);
        $aliases = $matches[1];
        $count = count($aliases);

        if ($count > $maxAliases) {
            throw new MaxAliasesExceededException($count, $maxAliases);
        }

        return $aliases;
    }

    /**
     * @return array{0: list<array{start: int, end: int}>, 1: array<string, list<string>>}
     */
    private function collectAnchorRanges(string $clean): array
    {
        preg_match_all('/(?<=[^a-zA-Z0-9_\-]|^)&([a-zA-Z0-9_\-]+)/', $clean, $matches, PREG_OFFSET_CAPTURE);
        $anchors = $matches[1];

        $ranges = [];
        $aliases = [];

        foreach ($anchors as $match) {
            $name = $match[0];
            $pos = $match[1];
            $nodeStart = $pos + strlen('&'.$name);

            $range = $this->nodeScanner->findNodeRange($clean, $pos, $nodeStart);
            $ranges[] = $range;

            $body = substr($clean, $range['start'], $range['end'] - $range['start']);
            preg_match_all('/(?<=[^a-zA-Z0-9_\-]|^)\*([a-zA-Z0-9_\-]+)/', $body, $bodyMatches);
            $aliases[$name] = $bodyMatches[1];
        }

        return [$ranges, $aliases];
    }

    /**
     * @param  list<array{start: int, end: int}>  $ranges
     * @return list<string>
     */
    private function extractTopLevelAliases(string $clean, array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $b['start'] <=> $a['start']);
        foreach ($ranges as $range) {
            $len = $range['end'] - $range['start'];
            $clean = substr_replace($clean, str_repeat(' ', $len), $range['start'], $len);
        }

        preg_match_all('/(?<=[^a-zA-Z0-9_\-]|^)\*([a-zA-Z0-9_\-]+)/', $clean, $matches);

        return $matches[1];
    }

    /**
     * @param  array<string, list<string>>  $anchorAliases
     * @param  list<string>  $topLevelAliases
     */
    private function assertNoBomb(array $anchorAliases, array $topLevelAliases, int $maxAliases): void
    {
        $memo = [];
        $visiting = [];

        $getExpansion = function (string $anchor) use (&$getExpansion, &$memo, &$visiting, $anchorAliases, $maxAliases): int {
            if (isset($visiting[$anchor])) {
                throw new MaxAliasesExceededException($maxAliases + 1, $maxAliases);
            }
            if (isset($memo[$anchor])) {
                return $memo[$anchor];
            }

            $visiting[$anchor] = true;
            $total = 0;
            $aliases = $anchorAliases[$anchor] ?? [];

            foreach ($aliases as $ref) {
                $total += 1 + $getExpansion($ref);
                if ($total > $maxAliases) {
                    throw new MaxAliasesExceededException($total, $maxAliases);
                }
            }

            unset($visiting[$anchor]);
            $memo[$anchor] = $total;

            return $total;
        };

        foreach (array_keys($anchorAliases) as $anchor) {
            $getExpansion($anchor);
        }

        $totalExpanded = 0;
        foreach ($topLevelAliases as $ref) {
            $totalExpanded += 1 + $getExpansion($ref);
            if ($totalExpanded > $maxAliases) {
                throw new MaxAliasesExceededException($totalExpanded, $maxAliases);
            }
        }
    }

    private function assertNoCustomTags(string $clean): void
    {
        if (!preg_match_all('/(?<=[^a-zA-Z0-9_\-]|^)!{1,2}([a-zA-Z0-9_\-\/]+)/', $clean, $matches)) {
            return;
        }

        foreach ($matches[0] as $tag) {
            if (preg_match('/^!!(?:str|int|float|bool|seq|map|null|binary|timestamp)$/i', $tag)) {
                continue;
            }
            throw new CustomTagsForbiddenException($tag);
        }
    }
}
