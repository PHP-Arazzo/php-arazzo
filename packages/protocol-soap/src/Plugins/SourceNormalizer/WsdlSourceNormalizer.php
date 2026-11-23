<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Plugins\SourceNormalizer;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Protocol\Soap\Exceptions\UnsupportedSourceVersionException;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Turns a described WSDL 1.1 source + raw content into a ResolvedOperation index.
 *
 * Index keys mirror OpenApiOperationResolver's accepted references:
 *   - plain operation name ("Add")
 *   - "$sourceDescriptions.<name>.<operationName>"
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsSourceNormalizerPlugin(SourceType::Wsdl)]
final class WsdlSourceNormalizer implements SourceNormalizerInterface
{
    private const WSDL_NS = 'http://schemas.xmlsoap.org/wsdl/';

    private const WSDL2_NS = 'http://www.w3.org/ns/wsdl';

    private const MEDIA_TYPE_XML = 'application/xml';

    private readonly WsdlDomReader $dom;

    private readonly WsdlComponentCollector $collector;

    public function __construct(
        ?WsdlDomReader $dom = null,
        ?WsdlComponentCollector $collector = null,
    ) {
        $this->dom = $dom ?? new WsdlDomReader();
        $this->collector = $collector ?? new WsdlComponentCollector($this->dom);
    }

    public function name(): string
    {
        return 'wsdl';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(SourceType $type): bool
    {
        return $type === SourceType::Wsdl;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
    {
        $dom = $this->dom->parse($rawContent);
        $root = $dom->documentElement;
        assert($root instanceof DOMElement);

        if ($root->namespaceURI === self::WSDL2_NS && $root->localName === 'description') {
            throw UnsupportedSourceVersionException::forVersion('2.0', (string) $source->name);
        }

        if ($root->namespaceURI !== self::WSDL_NS || $root->localName !== 'definitions') {
            throw new InvalidArgumentException('Source content is not a WSDL 1.1 document.');
        }

        return $this->buildIndex($dom, $root, $source);
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    private function buildIndex(DOMDocument $dom, DOMElement $root, SourceDescription $source): array
    {
        $messages = $this->collector->collectMessages($root);
        $portTypes = $this->collector->collectPortTypes($root);
        $bindings = $this->collector->collectBindings($root);
        $schemaXml = $this->dom->extractInlineSchema($dom, $root);
        $targetNamespace = $root->getAttribute('targetNamespace');

        $index = [];
        foreach ($this->collector->collectPorts($root) as $port) {
            $bindingName = $port['binding'];
            if ($bindingName === null || !isset($bindings[$bindingName])) {
                continue;
            }
            $binding = $bindings[$bindingName];
            $portTypeName = $binding['type'];
            if ($portTypeName === null || !isset($portTypes[$portTypeName])) {
                continue;
            }

            foreach ($portTypes[$portTypeName] as $operationName => $io) {
                $resolved = $this->resolveOperation($source, $operationName, [
                    'portType' => $portTypeName,
                    'location' => $port['location'],
                    'soapVersion' => $binding['soapVersion'],
                    'soapAction' => $binding['operations'][$operationName]['soapAction'] ?? null,
                    'namespace' => $targetNamespace === '' ? null : $targetNamespace,
                    'input' => $io['input'],
                    'output' => $io['output'],
                    'messages' => $messages,
                    'schemaXml' => $schemaXml,
                ]);

                $index[$operationName] = $resolved;
                $index['$sourceDescriptions.'.$source->name.'.'.$operationName] = $resolved;
            }
        }

        return $index;
    }

    /**
     * @param  array{
     *     portType: string,
     *     location: string,
     *     soapVersion: string,
     *     soapAction: ?string,
     *     namespace: ?string,
     *     input: ?string,
     *     output: ?string,
     *     messages: array<string, array{element: ?string}>,
     *     schemaXml: ?string,
     * }  $context
     */
    private function resolveOperation(SourceDescription $source, string $operationName, array $context): ResolvedOperation
    {
        $inputElement = null;
        if ($context['input'] !== null && isset($context['messages'][$context['input']])) {
            $inputElement = $context['messages'][$context['input']]['element'];
        }

        $outputElement = null;
        if ($context['output'] !== null && isset($context['messages'][$context['output']])) {
            $outputElement = $context['messages'][$context['output']]['element'];
        }

        $headerParameters = ['Content-Type' => ['value' => 'text/xml; charset=utf-8']];
        if ($context['soapAction'] !== null) {
            $headerParameters['SOAPAction'] = ['value' => $context['soapAction']];
        }

        $normalized = new NormalizedOpenApiOperation(
            path: $operationName,
            method: 'POST',
            resolvedServerUrl: $context['location'],
            pathParameters: [],
            queryParameters: [],
            headerParameters: $headerParameters,
            cookieParameters: [],
            requestBodies: [
                self::MEDIA_TYPE_XML => [
                    'xsdElement' => $inputElement,
                    'schema' => $context['schemaXml'],
                    'portType' => $context['portType'],
                    'soapVersion' => $context['soapVersion'],
                    'namespace' => $context['namespace'],
                ],
            ],
            responses: [
                self::MEDIA_TYPE_XML => [
                    'xsdElement' => $outputElement,
                    'schema' => $context['schemaXml'],
                ],
            ],
        );

        return new ResolvedOperation(
            source: $source,
            normalized: $normalized,
            operationName: $operationName,
        );
    }
}
