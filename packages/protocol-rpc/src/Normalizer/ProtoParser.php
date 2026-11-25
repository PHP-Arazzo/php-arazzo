<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Normalizer;

use Alama\Arazzo\Protocol\Rpc\Exceptions\ProtoSyntaxException;

/**
 * Hand-rolled, dependency-free `.proto` parser (plan F5.1). This is the
 * DF11/D5 vendored-codec exception: unlike the WSDL parser it never moves to
 * arazzo-sources — protocol-rpc owns `.proto` reading for the same reason the
 * four RPC variants share one package (one consumer, one parser).
 *
 * Covers the subset the slice needs: proto3 syntax header, package,
 * service/rpc declarations (including `stream`), message field declarations
 * (scalars, `repeated`, nested-message fields), and tolerates comments,
 * imports, options, enums, oneofs, maps, and reserved blocks by skipping
 * them. Garbage input throws InvalidArgumentException.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ProtoParser
{
    public function parse(string $content): ProtoDocument
    {
        $cursor = new ProtoTokenCursor(ProtoTokenizer::tokenize($content));

        $header = $cursor->expectWord('syntax');
        if ($header !== 'syntax') {
            throw ProtoSyntaxException::notProto3Document();
        }
        $cursor->expectSymbol('=');
        $version = $cursor->expectString();
        if ($version !== 'proto3') {
            throw ProtoSyntaxException::unsupportedSyntax($version);
        }
        $cursor->expectSymbol(';');

        $package = '';
        $services = [];
        $messages = [];

        while ($cursor->hasMore()) {
            $token = $cursor->peek();

            if ($token[0] === 'sym') {
                $cursor->consume();

                continue;
            }

            $keyword = $token[1];
            if (!in_array($keyword, ['package', 'import', 'option', 'enum', 'message', 'service', 'extend', 'oneof'], true)) {
                $this->skipDeclaration($cursor);

                continue;
            }
            $cursor->consume();

            match ($keyword) {
                'package' => $package = $this->qualifiedName($cursor),
                'import', 'option' => $this->skipToTerminator($cursor, ';'),
                'enum' => $this->skipBlock($cursor),
                'message' => $this->parseMessage($cursor, $messages),
                'service' => $services[] = $this->parseService($cursor),
                'extend', 'oneof' => $this->skipDeclaration($cursor),
            };
        }

        return new ProtoDocument($package, $services, $messages);
    }

    /**
     * @param  array<string, list<ProtoField>>  $messages
     */
    private function parseMessage(ProtoTokenCursor $cursor, array &$messages): void
    {
        $name = $cursor->expectWord('message-name');
        $cursor->expectSymbol('{');

        $fields = [];
        while (!$cursor->atSymbol('}') && $cursor->hasMore()) {
            $token = $cursor->peek();

            if ($token[0] === 'sym') {
                $cursor->consume();

                continue;
            }

            $keyword = $token[1];
            if (!in_array($keyword, ['repeated', 'map', 'message', 'oneof', 'group', 'extensions', 'reserved', 'option', 'extend'], true)) {
                $fields[] = $this->parseField($cursor, repeated: false);

                continue;
            }
            $cursor->consume();

            match ($keyword) {
                'repeated' => $fields[] = $this->parseField($cursor, repeated: true),
                'map', 'reserved', 'option', 'extend' => $this->skipToTerminator($cursor, ';'),
                'message', 'oneof', 'group', 'extensions' => $this->skipBlock($cursor),
            };
        }
        $cursor->expectSymbol('}');

        $messages[$name] = $fields;
    }

    private function parseField(ProtoTokenCursor $cursor, bool $repeated): ProtoField
    {
        $type = $this->qualifiedName($cursor);
        $name = $cursor->expectWord('field-name');
        $cursor->expectSymbol('=');
        $number = $cursor->expectNumber();

        if ($cursor->atSymbol('[')) {
            $cursor->consume();
            $this->skipToTerminator($cursor, ']');
        }
        $cursor->expectSymbol(';');

        return new ProtoField($name, $type, $number, $repeated);
    }

    private function parseService(ProtoTokenCursor $cursor): ProtoService
    {
        $name = $cursor->expectWord('service-name');
        $cursor->expectSymbol('{');

        $methods = [];
        while (!$cursor->atSymbol('}') && $cursor->hasMore()) {
            $token = $cursor->peek();

            if ($token[0] === 'sym') {
                $cursor->consume();

                continue;
            }

            $keyword = $token[1];
            $cursor->consume();

            if ($keyword === 'rpc') {
                $methods[] = $this->parseMethod($cursor);
            } else {
                $this->skipToTerminator($cursor, ';');
            }
        }
        $cursor->expectSymbol('}');

        return new ProtoService($name, $methods);
    }

    private function parseMethod(ProtoTokenCursor $cursor): ProtoMethod
    {
        $name = $cursor->expectWord('method-name');

        $cursor->expectSymbol('(');
        $requestStreaming = $cursor->peekWord() === 'stream';
        if ($requestStreaming) {
            $cursor->consume();
        }
        $requestType = $this->qualifiedName($cursor);
        $cursor->expectSymbol(')');

        $cursor->expectWord('returns');
        $cursor->expectSymbol('(');
        $responseStreaming = $cursor->peekWord() === 'stream';
        if ($responseStreaming) {
            $cursor->consume();
        }
        $responseType = $this->qualifiedName($cursor);
        $cursor->expectSymbol(')');

        if ($cursor->atSymbol('{')) {
            $this->skipBlock($cursor);
        }
        $cursor->expectSymbol(';');

        return new ProtoMethod($name, $requestType, $responseType, $requestStreaming, $responseStreaming);
    }

    private function qualifiedName(ProtoTokenCursor $cursor): string
    {
        $name = $cursor->expectWord('name');
        while ($cursor->atSymbol('.')) {
            $cursor->consume();
            $name .= '.'.$cursor->expectWord('name-segment');
        }

        return $name;
    }

    /**
     * Consumes a balanced `{ ... }` block including an optional leading name.
     */
    private function skipBlock(ProtoTokenCursor $cursor): void
    {
        if ($cursor->peekWord() !== null && !$cursor->atSymbol('{')) {
            $cursor->consume();
        }
        $cursor->expectSymbol('{');

        $depth = 1;
        while ($cursor->hasMore() && $depth > 0) {
            $token = $cursor->consume();
            if ($token[0] === 'sym') {
                if ($token[1] === '{') {
                    $depth++;
                } elseif ($token[1] === '}') {
                    $depth--;
                }
            }
        }
    }

    /**
     * Skips a declaration that may be a `;` statement or a `{ ... }` block.
     */
    private function skipDeclaration(ProtoTokenCursor $cursor): void
    {
        if ($cursor->atSymbol('{')) {
            $this->skipBlock($cursor);

            return;
        }

        $this->skipToTerminator($cursor, ';');
    }

    /**
     * Advances to the first `$terminal` symbol at brace depth zero.
     */
    private function skipToTerminator(ProtoTokenCursor $cursor, string $terminal): void
    {
        $depth = 0;
        while ($cursor->hasMore()) {
            $token = $cursor->consume();
            if ($token[0] !== 'sym') {
                continue;
            }
            if ($token[1] === '{') {
                $depth++;
            } elseif ($token[1] === '}') {
                $depth--;
            } elseif ($token[1] === $terminal && $depth === 0) {
                return;
            }
        }
    }
}
