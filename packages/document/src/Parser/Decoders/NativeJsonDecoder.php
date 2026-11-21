<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Decoders;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxDepthExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxNodesExceededException;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;
use JsonException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class NativeJsonDecoder implements DecoderInterface
{
    private readonly DecodePolicy $policy;

    public function __construct(?DecodePolicy $policy = null)
    {
        $this->policy = $policy ?? DecodePolicy::default();
    }

    public function decode(string $source): mixed
    {
        $byteLength = strlen($source);
        if ($byteLength > $this->policy->maxBytes) {
            throw new MaxBytesExceededException($byteLength, $this->policy->maxBytes);
        }

        $depth = max(1, $this->policy->maxDepth);

        try {
            $data = json_decode($source, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getCode() === JSON_ERROR_DEPTH || str_contains($e->getMessage(), 'depth')) {
                throw new MaxDepthExceededException($this->policy->maxDepth + 1, $this->policy->maxDepth, previous: $e);
            }
            throw new DecodeException($e->getMessage(), 0, $e);
        }

        $this->assertNodes($data);

        return $data;
    }

    private function assertNodes(mixed $data): void
    {
        $maxNodes = $this->policy->maxNodes;
        $nodeCount = 0;

        $traverse = function (mixed $node) use (&$traverse, &$nodeCount, $maxNodes): void {
            $nodeCount++;
            if ($nodeCount > $maxNodes) {
                throw new MaxNodesExceededException($nodeCount, $maxNodes);
            }

            if (is_array($node)) {
                foreach ($node as $child) {
                    $traverse($child);
                }
            }
        };

        $traverse($data);
    }
}
