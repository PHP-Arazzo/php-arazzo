<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Plugins\SourceNormalizer;

use DOMElement;

/**
 * Extracts WSDL 1.1 messages, portTypes, SOAP bindings, and service ports
 * from a parsed definitions root element.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WsdlComponentCollector
{
    private const SOAP_NS = 'http://schemas.xmlsoap.org/wsdl/soap/';

    private const SOAP12_NS = 'http://schemas.xmlsoap.org/wsdl/soap12/';

    public function __construct(
        private readonly WsdlDomReader $dom = new WsdlDomReader(),
    ) {}

    /**
     * @return array<string, array{element: ?string}>
     */
    public function collectMessages(DOMElement $root): array
    {
        /** @var array<string, array{element: ?string}> $messages */
        $messages = [];
        foreach ($this->dom->children($root, 'message') as $message) {
            $name = $message->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $part = $this->dom->firstChild($message, 'part');
            $element = $part?->getAttribute('element');
            $messages[$name] = ['element' => $element === '' ? null : $element];
        }

        return $messages;
    }

    /**
     * @return array<string, array<string, array{input: ?string, output: ?string}>>
     */
    public function collectPortTypes(DOMElement $root): array
    {
        /** @var array<string, array<string, array{input: ?string, output: ?string}>> $portTypes */
        $portTypes = [];
        foreach ($this->dom->children($root, 'portType') as $portType) {
            $name = $portType->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $operations = [];
            foreach ($this->dom->children($portType, 'operation') as $operation) {
                $operationName = $operation->getAttribute('name');
                if ($operationName === '') {
                    continue;
                }
                $operations[$operationName] = [
                    'input' => $this->messageReference($operation, 'input'),
                    'output' => $this->messageReference($operation, 'output'),
                ];
            }
            $portTypes[$name] = $operations;
        }

        return $portTypes;
    }

    /**
     * @return array<string, array{type: ?string, soapVersion: string, operations: array<string, array{soapAction: ?string}>}>
     */
    public function collectBindings(DOMElement $root): array
    {
        /** @var array<string, array{type: ?string, soapVersion: string, operations: array<string, array{soapAction: ?string}>}> $bindings */
        $bindings = [];
        foreach ($this->dom->children($root, 'binding') as $binding) {
            $name = $binding->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $soapBinding = $this->dom->firstChildIn($binding, 'binding', [self::SOAP_NS, self::SOAP12_NS]);
            $soapVersion = $soapBinding !== null && $soapBinding->namespaceURI === self::SOAP12_NS ? '1.2' : '1.1';

            $type = $this->dom->localName($binding->getAttribute('type'));

            $bindings[$name] = [
                'type' => $type === '' ? null : $type,
                'soapVersion' => $soapVersion,
                'operations' => $this->collectBindingOperations($binding),
            ];
        }

        return $bindings;
    }

    /**
     * @return array<string, array{location: string, binding: ?string}>
     */
    public function collectPorts(DOMElement $root): array
    {
        /** @var array<string, array{location: string, binding: ?string}> $ports */
        $ports = [];
        foreach ($this->dom->children($root, 'service') as $service) {
            foreach ($this->dom->children($service, 'port') as $port) {
                $name = $port->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $address = $this->dom->firstChildIn($port, 'address', [self::SOAP_NS, self::SOAP12_NS]);
                $location = $address?->getAttribute('location');
                if ($location === null || $location === '') {
                    continue;
                }
                $binding = $this->dom->localName($port->getAttribute('binding'));

                $ports[$name] = [
                    'location' => $location,
                    'binding' => $binding === '' ? null : $binding,
                ];
            }
        }

        return $ports;
    }

    /**
     * @return array<string, array{soapAction: ?string}>
     */
    private function collectBindingOperations(DOMElement $binding): array
    {
        /** @var array<string, array{soapAction: ?string}> $operations */
        $operations = [];
        foreach ($this->dom->children($binding, 'operation') as $operation) {
            $operationName = $operation->getAttribute('name');
            if ($operationName === '') {
                continue;
            }
            $soapOperation = $this->dom->firstChildIn($operation, 'operation', [self::SOAP_NS, self::SOAP12_NS]);
            $soapAction = $soapOperation?->getAttribute('soapAction');
            $operations[$operationName] = ['soapAction' => $soapAction === '' || $soapAction === null ? null : $soapAction];
        }

        return $operations;
    }

    private function messageReference(DOMElement $operation, string $localName): ?string
    {
        $node = $this->dom->firstChild($operation, $localName);
        $reference = $node?->getAttribute('message');

        if ($reference === null || $reference === '') {
            return null;
        }

        return $this->dom->localName($reference);
    }
}
