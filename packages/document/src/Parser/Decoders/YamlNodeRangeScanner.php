<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Decoders;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class YamlNodeRangeScanner
{
    /**
     * @return array{start: int, end: int}
     */
    public function findNodeRange(string $text, int $anchorOffset, int $nodeStart): array
    {
        $len = strlen($text);
        $firstCharPos = $nodeStart;

        while ($firstCharPos < $len && ($text[$firstCharPos] === ' ' || $text[$firstCharPos] === "\t")) {
            $firstCharPos++;
        }

        if ($firstCharPos >= $len) {
            return ['start' => $nodeStart, 'end' => $len];
        }

        $char = $text[$firstCharPos];
        if ($char === '[' || $char === '{') {
            return $this->findFlowRange($text, $firstCharPos, $char, $len);
        }

        return $this->findBlockRange($text, $anchorOffset, $nodeStart, $len);
    }

    /**
     * @return array{start: int, end: int}
     */
    private function findFlowRange(string $text, int $start, string $char, int $len): array
    {
        $closing = $char === '[' ? ']' : '}';
        $depth = 1;
        $pos = $start + 1;

        while ($pos < $len && $depth > 0) {
            if ($text[$pos] === $char) {
                $depth++;
            } elseif ($text[$pos] === $closing) {
                $depth--;
            }
            $pos++;
        }

        return ['start' => $start, 'end' => $pos];
    }

    /**
     * @return array{start: int, end: int}
     */
    private function findBlockRange(string $text, int $anchorOffset, int $nodeStart, int $len): array
    {
        $lineIndent = $this->findLineIndent($text, $anchorOffset);

        $nextNewline = strpos($text, "\n", $anchorOffset);
        if ($nextNewline === false) {
            return ['start' => $nodeStart, 'end' => $len];
        }

        $pos = $nextNewline + 1;
        $endPos = $pos;

        while ($pos < $len) {
            $nextLineEnd = strpos($text, "\n", $pos);
            $line = $nextLineEnd === false ? substr($text, $pos) : substr($text, $pos, $nextLineEnd - $pos);

            if (trim($line) === '') {
                $pos = $nextLineEnd === false ? $len : $nextLineEnd + 1;

                continue;
            }

            if ($this->lineIndent($line) <= $lineIndent) {
                break;
            }

            $endPos = $nextLineEnd === false ? $len : $nextLineEnd + 1;
            $pos = $endPos;
        }

        return ['start' => $nodeStart, 'end' => max($nodeStart, $endPos)];
    }

    private function findLineIndent(string $text, int $offset): int
    {
        $lastNewline = strrpos(substr($text, 0, $offset), "\n");
        $lineStart = ($lastNewline === false) ? 0 : $lastNewline + 1;

        return $this->lineIndent(substr($text, $lineStart));
    }

    private function lineIndent(string $line): int
    {
        $indent = 0;
        $len = strlen($line);
        while ($indent < $len && $line[$indent] === ' ') {
            $indent++;
        }

        return $indent;
    }
}
