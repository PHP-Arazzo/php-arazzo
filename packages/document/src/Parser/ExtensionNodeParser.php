<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\InteractionMode;
use Alama\Arazzo\Contracts\Spec\GraphQlOperation;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses GraphQL operation and actor interaction sub-nodes shared by steps and components.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ExtensionNodeParser extends FieldParser
{
    public function __construct(
        private readonly ValueParser $values,
    ) {}

    public function parseGraphQlOperation(mixed $node, ParseContext $ctx): GraphQlOperation
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $schema = $this->requireString($obj, 'schema', $ctx);
        $operation = $this->requireString($obj, 'operation', $ctx);
        $extensions = $this->optionalArray($obj, 'extensions', $ctx);
        $extensionsSelector = null;
        if (array_key_exists('extensionsSelector', $obj) && $obj['extensionsSelector'] !== null) {
            $extensionsSelector = $this->values->parseSelector($obj['extensionsSelector'], $ctx->push('extensionsSelector'));
        }

        return new GraphQlOperation(
            schema: $schema,
            operation: $operation,
            extensions: $extensions,
            extensionsSelector: $extensionsSelector,
        );
    }

    public function parseInteraction(mixed $node, ParseContext $ctx): Interaction
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $prompt = $this->optionalString($obj, 'prompt', $ctx);
        $timeout = $this->optionalString($obj, 'timeout', $ctx);
        $modeRaw = $this->optionalString($obj, 'mode', $ctx);
        $mode = $modeRaw !== null ? InteractionMode::tryFrom($modeRaw) : null;
        if ($modeRaw !== null && $mode === null) {
            throw ParserException::invalidEnum($ctx->push('mode'), 'form|redirect|acknowledge', $modeRaw);
        }
        $context = $this->optionalArray($obj, 'context', $ctx);
        $inputSchema = $this->optionalArray($obj, 'inputSchema', $ctx);
        $redirect = null;
        if (array_key_exists('redirect', $obj) && $obj['redirect'] !== null) {
            $redirectObj = $this->requireObjectMap($obj['redirect'], $ctx->push('redirect'));
            $redirect = $this->optionalString($redirectObj, 'operationId', $ctx->push('redirect'));
        }

        return new Interaction(
            expectedPayload: $context,
            timeout: $timeout,
            mode: $mode,
            prompt: $prompt,
            redirectOperationId: $redirect,
            inputSchema: $inputSchema,
        );
    }
}
