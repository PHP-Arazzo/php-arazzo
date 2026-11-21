<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Decoders;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxDepthExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxNodesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\ObjectDeserializationForbiddenException;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SymfonyYamlDecoder implements DecoderInterface
{
    private readonly DecodePolicy $policy;

    private readonly YamlSecurityScanner $scanner;

    public function __construct(?DecodePolicy $policy = null)
    {
        $this->policy = $policy ?? DecodePolicy::default();
        $this->scanner = new YamlSecurityScanner();
    }

    public function decode(string $source): mixed
    {
        $byteLength = strlen($source);
        if ($byteLength > $this->policy->maxBytes) {
            throw new MaxBytesExceededException($byteLength, $this->policy->maxBytes);
        }

        $this->scanner->scanTags($source, $this->policy);
        $this->scanner->scanAliases($source, $this->policy);

        $flags = Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
        if ($this->policy->yaml->allowCustomTags) {
            $flags |= Yaml::PARSE_CUSTOM_TAGS;
        }
        if ($this->policy->yaml->allowObjectDeserialization) {
            $flags |= Yaml::PARSE_OBJECT | Yaml::PARSE_OBJECT_FOR_MAP;
        }

        try {
            $data = Yaml::parse($source, $flags);
        } catch (ParseException $e) {
            throw new DecodeException($e->getMessage(), 0, $e);
        }

        $this->assertDepthAndNodes($data);

        return $data;
    }

    private function assertDepthAndNodes(mixed $data): void
    {
        $maxDepth = $this->policy->maxDepth;
        $maxNodes = $this->policy->maxNodes;
        $allowObjects = $this->policy->yaml->allowObjectDeserialization;
        $nodeCount = 0;

        $traverse = function (mixed $node, int $depth) use (&$traverse, &$nodeCount, $maxDepth, $maxNodes, $allowObjects): void {
            $nodeCount++;
            if ($nodeCount > $maxNodes) {
                throw new MaxNodesExceededException($nodeCount, $maxNodes);
            }
            if ($depth > $maxDepth) {
                throw new MaxDepthExceededException($depth, $maxDepth);
            }

            if (is_object($node) && !$allowObjects) {
                throw new ObjectDeserializationForbiddenException();
            }

            if (is_array($node)) {
                foreach ($node as $child) {
                    $traverse($child, $depth + 1);
                }
            }
        };

        $traverse($data, 1);
    }
}
