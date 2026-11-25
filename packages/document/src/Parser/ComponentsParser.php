<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses the Arazzo `components` object (inputs, parameters, actions, interactions).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ComponentsParser extends FieldParser
{
    public function __construct(
        private readonly ValueParser $values,
        private readonly ActionParser $actions,
        private readonly ExtensionNodeParser $extensions,
    ) {}

    public function parseComponents(mixed $node, ParseContext $ctx): Components
    {
        if ($node === null) {
            return new Components([], [], [], []);
        }
        $obj = $this->requireObjectMap($node, $ctx);

        return new Components(
            inputs: $this->parseComponentInputs($obj, $ctx),
            parameters: $this->parseComponentParameters($obj, $ctx),
            successActions: $this->parseComponentActions($obj, 'successActions', $ctx, $this->actions->parseSuccessAction(...)),
            failureActions: $this->parseComponentActions($obj, 'failureActions', $ctx, $this->actions->parseFailureAction(...)),
            interactions: $this->parseComponentInteractions($obj, $ctx),
        );
    }

    /**
     * @param  array<string,mixed>  $obj
     * @return array<string,array<string,mixed>>
     */
    private function parseComponentInputs(array $obj, ParseContext $ctx): array
    {
        $items = $this->optionalArray($obj, 'inputs', $ctx);
        if ($items === null) {
            return [];
        }

        $inputs = [];
        foreach ($items as $k => $v) {
            if (!is_array($v)) {
                throw ParserException::wrongType(
                    $ctx->push('inputs')->push((string) $k), 'object (JSON Schema)', $v,
                );
            }
            /** @var array<string,mixed> $v */
            $inputs[(string) $k] = $v;
        }

        return $inputs;
    }

    /**
     * @param  array<string,mixed>  $obj
     * @return array<string,Parameter>
     */
    private function parseComponentParameters(array $obj, ParseContext $ctx): array
    {
        $items = $this->optionalArray($obj, 'parameters', $ctx);
        if ($items === null) {
            return [];
        }

        $parameters = [];
        foreach ($items as $k => $v) {
            $parsed = $this->values->parseParameter($v, $ctx->push('parameters')->push((string) $k));
            if ($parsed instanceof Reusable) {
                throw ParserException::wrongType(
                    $ctx->push('parameters')->push((string) $k),
                    'parameter (not a reusable ref)', $v,
                );
            }
            $parameters[(string) $k] = $parsed;
        }

        return $parameters;
    }

    /**
     * @template T of SuccessAction|FailureAction
     *
     * @param  array<string,mixed>  $obj
     * @param  callable(mixed, ParseContext): (T|Reusable)  $parse
     * @return array<string,T>
     */
    private function parseComponentActions(array $obj, string $key, ParseContext $ctx, callable $parse): array
    {
        $items = $this->optionalArray($obj, $key, $ctx);
        if ($items === null) {
            return [];
        }

        $parsed = [];
        foreach ($items as $k => $v) {
            $action = $parse($v, $ctx->push($key)->push((string) $k));
            if ($action instanceof Reusable) {
                throw ParserException::wrongType(
                    $ctx->push($key)->push((string) $k),
                    'action (not a reusable ref)', $v,
                );
            }
            $parsed[(string) $k] = $action;
        }

        return $parsed;
    }

    /**
     * @param  array<string,mixed>  $obj
     * @return array<string,Interaction>
     */
    private function parseComponentInteractions(array $obj, ParseContext $ctx): array
    {
        $items = $this->optionalArray($obj, 'interactions', $ctx);
        if ($items === null) {
            return [];
        }

        $interactions = [];
        foreach ($items as $k => $v) {
            if (!is_array($v)) {
                throw ParserException::wrongType(
                    $ctx->push('interactions')->push((string) $k), 'object', $v,
                );
            }
            $interactions[(string) $k] = $this->extensions->parseInteraction($v, $ctx->push('interactions')->push((string) $k));
        }

        return $interactions;
    }
}
