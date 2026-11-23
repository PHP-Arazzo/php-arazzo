<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Plugins\SourceNormalizer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Low-level WSDL DOM loader, namespace-aware element traversal, and inline
 * XSD serializer.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WsdlDomReader
{
    private const XML_SCHEMA_NS = 'http://www.w3.org/2001/XMLSchema';

    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

    public function parse(string $rawContent): DOMDocument
    {
        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $dom->loadXML($rawContent, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw new InvalidArgumentException('Failed to parse source content as XML.');
        }

        return $dom;
    }

    public function extractInlineSchema(DOMDocument $dom, DOMElement $root): ?string
    {
        $types = $this->firstChild($root, 'types');
        if ($types === null) {
            return null;
        }

        $schema = $this->firstChildIn($types, 'schema', [self::XML_SCHEMA_NS]);
        if ($schema === null) {
            return null;
        }

        return $this->serializeSchema($dom, $schema);
    }

    /**
     * @return list<DOMElement>
     */
    public function children(DOMNode $parent, string $localName): array
    {
        $found = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $found[] = $child;
            }
        }

        return $found;
    }

    public function firstChild(DOMNode $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $namespaces
     */
    public function firstChildIn(DOMNode $parent, string $localName, array $namespaces): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->localName === $localName
                && in_array($child->namespaceURI, $namespaces, true)
            ) {
                return $child;
            }
        }

        return null;
    }

    public function localName(string $qname): string
    {
        $pos = strpos($qname, ':');

        return $pos === false ? $qname : substr($qname, $pos + 1);
    }

    private function serializeSchema(DOMDocument $dom, DOMElement $schema): ?string
    {
        $container = new DOMDocument('1.0', 'UTF-8');
        $imported = $container->importNode($schema, true);
        assert($imported instanceof DOMElement);

        $xpath = new DOMXPath($dom);
        $namespaces = $xpath->query('namespace::*', $schema);
        assert($namespaces !== false);

        foreach ($namespaces as $namespaceNode) {
            $prefix = $namespaceNode->localName;
            $uri = $namespaceNode->nodeValue;
            if ($prefix === 'xml' || $uri === null || $uri === '') {
                continue;
            }

            $qualified = $prefix === null || $prefix === '' ? 'xmlns' : 'xmlns:'.$prefix;
            $imported->setAttributeNS(self::XMLNS_NS, $qualified, $uri);
        }

        return $container->saveXML($imported) ?: null;
    }
}
