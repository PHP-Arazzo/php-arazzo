<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Document\Parser\ActionParser;
use Alama\Arazzo\Document\Parser\ComponentsParser;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Document\Parser\ExtensionNodeParser;
use Alama\Arazzo\Document\Parser\ParseContext;
use Alama\Arazzo\Document\Parser\RequestBodyParser;
use Alama\Arazzo\Document\Parser\StepParser;
use Alama\Arazzo\Document\Parser\ValueParser;

it('covers ValueParser edge cases: reusable parameter, typed selector object, action parameters, criteria list, and wrong types', function (): void {
    $values = new ValueParser();
    $ctx = new ParseContext('/test');

    // Reusable parameter
    $reusable = $values->parseParameter(['reference' => '$components.parameters.p', 'value' => 'v'], $ctx);
    expect($reusable)->toBeInstanceOf(Reusable::class);

    // Non-scalar/non-array value fails parseValueOrSelector & reusable value normalization
    expect(fn () => $values->parseValueOrSelector(new stdClass(), $ctx))->toThrow(ParserException::class);
    expect(fn () => $values->parseReusable(['reference' => '$components.parameters.p', 'value' => new stdClass()], $ctx))
        ->toThrow(ParserException::class);

    // Selector with Expression Type Object {type, version}
    $selector = $values->parseSelector([
        'selector' => '//item',
        'type' => ['type' => 'xpath', 'version' => 'xpath-31'],
    ], $ctx);
    expect($selector->type)->toBe(ExpressionType::XPath)
        ->and($selector->version)->toBe('xpath-31');

    // Action parameters: missing workflowId throws; valid workflowId parses parameters
    expect(fn () => $values->parseActionParameters(['parameters' => [['name' => 'a', 'value' => '1']]], $ctx))
        ->toThrow(ParserException::class, 'workflowId');
    $actionParams = $values->parseActionParameters([
        'workflowId' => 'wf2',
        'parameters' => [['name' => 'a', 'value' => '1']],
    ], $ctx);
    expect($actionParams)->toHaveCount(1);

    // Criteria list parsing
    $criteria = $values->parseCriteriaList(['criteria' => [['condition' => '$statusCode == 200']]], $ctx);
    expect($criteria)->toHaveCount(1);
});

it('covers RequestBodyParser, StepParser, ExtensionNodeParser, and ComponentsParser error/variant paths', function (): void {
    $values = new ValueParser();
    $requestBodies = new RequestBodyParser($values);
    $actions = new ActionParser($values);
    $extensions = new ExtensionNodeParser($values);
    $steps = new StepParser($values, $actions, $extensions, $requestBodies);
    $components = new ComponentsParser($values, $actions, $extensions);
    $ctx = new ParseContext('/test');

    // RequestBodyParser targetSelectorType string valid, string invalid, and object map form
    $r1 = $requestBodies->parsePayloadReplacement(['target' => '/a', 'value' => 1, 'targetSelectorType' => 'jsonpath'], $ctx);
    expect($r1->targetSelectorType)->toBe('jsonpath');

    $r2 = $requestBodies->parsePayloadReplacement(['target' => '/a', 'value' => 1, 'targetSelectorType' => ['type' => 'xpath', 'version' => 'xpath-31']], $ctx);
    expect($r2->targetSelectorType)->toBe(['type' => 'xpath', 'version' => 'xpath-31']);

    expect(fn () => $requestBodies->parsePayloadReplacement(['target' => '/a', 'value' => 1, 'targetSelectorType' => 'bogus'], $ctx))
        ->toThrow(ParserException::class);

    // StepParser workflowId target, invalid rpcProtocol, valid & invalid dependsOn
    $wfStep = $steps->parseStep(['stepId' => 's1', 'workflowId' => 'childWf', 'dependsOn' => ['s0']], $ctx);
    expect($wfStep->target->workflowId)->toBe('childWf')
        ->and($wfStep->flow->dependsOn)->toBe(['s0']);

    expect(fn () => $steps->parseStep(['stepId' => 's2', 'rpcMethod' => 'Svc/M', 'rpcProtocol' => 'invalid'], $ctx))
        ->toThrow(ParserException::class);

    expect(fn () => $steps->parseStep(['stepId' => 's3', 'operationId' => 'op', 'dependsOn' => [123]], $ctx))
        ->toThrow(ParserException::class);

    // ExtensionNodeParser invalid interaction mode
    expect(fn () => $extensions->parseInteraction(['mode' => 'telepathy'], $ctx))
        ->toThrow(ParserException::class);

    // ComponentsParser rejects reusable ref in component parameters and non-array interaction
    expect(fn () => $components->parseComponents(['parameters' => ['bad' => ['reference' => '$components.parameters.other']]], $ctx))
        ->toThrow(ParserException::class);

    expect(fn () => $components->parseComponents(['interactions' => ['bad' => 'not-an-object']], $ctx))
        ->toThrow(ParserException::class);
});
