<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses `requestBody` and `replacements` objects.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class RequestBodyParser extends FieldParser
{
    public function __construct(
        private readonly ValueParser $values,
    ) {}

    public function parseRequestBody(mixed $node, ParseContext $ctx): RequestBody
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $replacements = [];
        $rawRepl = $this->optionalList($obj, 'replacements', $ctx);
        if ($rawRepl !== null) {
            foreach ($rawRepl as $i => $item) {
                $replacements[] = $this->parsePayloadReplacement($item, $ctx->push('replacements')->push((string) $i));
            }
        }

        return new RequestBody(
            contentType: $this->optionalString($obj, 'contentType', $ctx),
            payload: array_key_exists('payload', $obj)
                ? $this->values->parseExpressionOrValue($obj['payload'])
                : null,
            replacements: $replacements,
        );
    }

    public function parsePayloadReplacement(mixed $node, ParseContext $ctx): PayloadReplacement
    {
        $obj = $this->requireObjectMap($node, $ctx);
        if (!array_key_exists('value', $obj)) {
            throw ParserException::missingField($ctx, 'value');
        }

        $selectorType = null;
        $tst = $obj['targetSelectorType'] ?? null;
        if (is_array($tst) && !array_is_list($tst)) {
            /** @var array<string, mixed> $tst */
            $selectorType = $tst;
        } elseif (($raw = $this->optionalString($obj, 'targetSelectorType', $ctx)) !== null) {
            $selectorType = in_array($raw, ['jsonpointer', 'jsonpath', 'xpath'], true)
                ? $raw
                : throw ParserException::invalidEnum($ctx->push('targetSelectorType'), 'jsonpointer|jsonpath|xpath', $raw);
        }

        return new PayloadReplacement(
            target: $this->requireString($obj, 'target', $ctx),
            value: $this->values->parseValueOrSelector($obj['value'], $ctx->push('value'), false),
            targetSelectorType: $selectorType,
        );
    }
}
