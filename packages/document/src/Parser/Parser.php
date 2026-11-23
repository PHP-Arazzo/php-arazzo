<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\RawDocument;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use InvalidArgumentException;

/**
 * Recursive-descent parser for the Arazzo document grammar.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class Parser extends FieldParser
{
    private readonly ValueParser $values;

    private readonly RequestBodyParser $requestBodies;

    private readonly ActionParser $actions;

    private readonly StepParser $steps;

    private readonly ComponentsParser $components;

    public function __construct()
    {
        $this->values = new ValueParser();
        $this->requestBodies = new RequestBodyParser($this->values);
        $this->actions = new ActionParser($this->values);
        $extensions = new ExtensionNodeParser($this->values);
        $this->steps = new StepParser($this->values, $this->actions, $extensions, $this->requestBodies);
        $this->components = new ComponentsParser($this->values, $this->actions, $extensions);
    }

    public function parse(RawDocument $raw): ArazzoDocument
    {
        $ctx = new ParseContext($raw->path);
        $d = $raw->data;

        $arazzo = $this->requireString($d, 'arazzo', $ctx);

        try {
            $specVersion = SpecVersion::fromRaw($arazzo);
        } catch (InvalidArgumentException) {
            throw ParserException::unsupportedVersion($ctx, $arazzo);
        }

        $self = $this->optionalString($d, '$self', $ctx);

        if (!array_key_exists('info', $d)) {
            throw ParserException::missingField($ctx, 'info');
        }
        $info = $this->parseInfo($d['info'], $ctx->push('info'));

        $sourceDescriptions = $this->parseOptionalListOf($d, 'sourceDescriptions', $ctx, $this->parseSourceDescription(...));

        if (!array_key_exists('workflows', $d)) {
            throw ParserException::missingField($ctx, 'workflows');
        }
        $workflows = $this->parseOptionalListOf($d, 'workflows', $ctx, $this->parseWorkflow(...));

        $components = $this->components->parseComponents($d['components'] ?? null, $ctx->push('components'));

        $extensions = array_filter(
            $d,
            static fn ($k): bool => str_starts_with((string) $k, 'x-'),
            ARRAY_FILTER_USE_KEY,
        );

        return new ArazzoDocument(
            arazzo: $arazzo,
            info: $info,
            sourceDescriptions: $sourceDescriptions,
            workflows: $workflows,
            components: $components,
            specificationExtensions: $extensions,
            rawRoot: $d,
            specVersion: $specVersion,
            self: $self,
        );
    }

    protected function parseInfo(mixed $node, ParseContext $ctx): Info
    {
        $obj = $this->requireObjectMap($node, $ctx);

        return new Info(
            title: $this->requireString($obj, 'title', $ctx),
            summary: $this->optionalString($obj, 'summary', $ctx),
            description: $this->optionalString($obj, 'description', $ctx),
            version: $this->requireString($obj, 'version', $ctx),
        );
    }

    protected function parseSourceDescription(mixed $node, ParseContext $ctx): SourceDescription
    {
        $obj = $this->requireObjectMap($node, $ctx);
        $type = $this->requireString($obj, 'type', $ctx);
        $enum = SourceType::tryFrom($type)
            ?? throw ParserException::invalidEnum(
                $ctx->push('type'), 'openapi|arazzo|asyncapi|wsdl|protobuf|graphql', $type,
            );

        return new SourceDescription(
            name: $this->requireString($obj, 'name', $ctx),
            url: $this->requireString($obj, 'url', $ctx),
            type: $enum,
        );
    }

    protected function parseWorkflow(mixed $node, ParseContext $ctx): Workflow
    {
        $obj = $this->requireObjectMap($node, $ctx);

        $inputs = $this->optionalArray($obj, 'inputs', $ctx);

        $dependsOn = [];
        if (($d = $this->optionalList($obj, 'dependsOn', $ctx)) !== null) {
            foreach ($d as $i => $item) {
                if (!is_string($item)) {
                    throw ParserException::wrongType(
                        $ctx->push('dependsOn')->push($i), 'string', $item,
                    );
                }
                $dependsOn[] = $item;
            }
        }

        if (!array_key_exists('steps', $obj)) {
            throw ParserException::missingField($ctx, 'steps');
        }
        $steps = [];
        foreach ($this->requireList($obj['steps'], $ctx->push('steps')) as $i => $item) {
            $steps[] = $this->steps->parseStep($item, $ctx->push('steps')->push($i));
        }

        $successActions = $this->parseOptionalListOf($obj, 'successActions', $ctx, $this->parseSuccessAction(...));
        $failureActions = $this->parseOptionalListOf($obj, 'failureActions', $ctx, $this->parseFailureAction(...));
        $parameters = $this->parseOptionalListOf($obj, 'parameters', $ctx, $this->parseParameter(...));

        $outputs = [];
        if (array_key_exists('outputs', $obj) && $obj['outputs'] !== null) {
            $outputs = $this->parseOutputsMap($obj['outputs'], $ctx->push('outputs'));
        }

        /** @var array<string,mixed>|null $inputs */
        return new Workflow(
            workflowId: $this->requireString($obj, 'workflowId', $ctx),
            summary: $this->optionalString($obj, 'summary', $ctx),
            description: $this->optionalString($obj, 'description', $ctx),
            inputs: $inputs,
            dependsOn: $dependsOn,
            steps: $steps,
            successActions: $successActions,
            failureActions: $failureActions,
            outputs: $outputs,
            parameters: $parameters,
        );
    }

    protected function parseParameter(mixed $node, ParseContext $ctx): Parameter|Reusable
    {
        return $this->values->parseParameter($node, $ctx);
    }

    protected function parseRequestBody(mixed $node, ParseContext $ctx): RequestBody
    {
        return $this->requestBodies->parseRequestBody($node, $ctx);
    }

    protected function parsePayloadReplacement(mixed $node, ParseContext $ctx): PayloadReplacement
    {
        return $this->requestBodies->parsePayloadReplacement($node, $ctx);
    }

    protected function parseExpressionOrValue(mixed $node): mixed
    {
        return $this->values->parseExpressionOrValue($node);
    }

    protected function parseSuccessCriterion(mixed $node, ParseContext $ctx): SuccessCriterion
    {
        return $this->values->parseSuccessCriterion($node, $ctx);
    }

    protected function parseSuccessAction(mixed $node, ParseContext $ctx): SuccessAction|Reusable
    {
        return $this->actions->parseSuccessAction($node, $ctx);
    }

    protected function parseFailureAction(mixed $node, ParseContext $ctx): FailureAction|Reusable
    {
        return $this->actions->parseFailureAction($node, $ctx);
    }

    protected function parseReusable(mixed $node, ParseContext $ctx): Reusable
    {
        return $this->values->parseReusable($node, $ctx);
    }

    /** @return array<string,Expression|Selector|scalar|array<mixed>|null> */
    protected function parseOutputsMap(mixed $node, ParseContext $ctx): array
    {
        return $this->values->parseOutputsMap($node, $ctx);
    }

    /**
     * @template T
     *
     * @param  array<string,mixed>  $obj
     * @param  callable(mixed, ParseContext): T  $parse
     * @return list<T>
     */
    private function parseOptionalListOf(array $obj, string $key, ParseContext $ctx, callable $parse): array
    {
        $items = $this->optionalList($obj, $key, $ctx);
        if ($items === null) {
            return [];
        }

        $parsed = [];
        foreach ($items as $i => $item) {
            $parsed[] = $parse($item, $ctx->push($key)->push($i));
        }

        return $parsed;
    }
}
