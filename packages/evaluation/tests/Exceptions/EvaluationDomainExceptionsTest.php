<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Evaluation\Exceptions;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\BuiltInCriterionEvaluator;
use Alama\Arazzo\Evaluation\Condition\AccessorParser;
use Alama\Arazzo\Evaluation\Condition\ConditionEvaluator;
use Alama\Arazzo\Evaluation\Condition\ConditionSyntaxException;
use Alama\Arazzo\Evaluation\Condition\Lexer;
use Alama\Arazzo\Evaluation\Condition\Parser;
use Alama\Arazzo\Evaluation\CriteriaEvaluator;
use Alama\Arazzo\Evaluation\Exceptions\CriterionEvaluationException;
use Alama\Arazzo\Evaluation\Exceptions\RegistryInitializationException;
use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\ExpressionEngine;
use stdClass;

afterEach(function (): void {
    ExpressionEvaluatorRegistry::reset();
    CriterionEvaluatorRegistry::reset();
});

it('covers evaluation domain exception factories, CriteriaEvaluator defaults, and BuiltInCriterionEvaluator stringify/regex edge cases', function (): void {
    $parser = new Parser(new Lexer());
    expect(fn () => $parser->parse('== 1'))->toThrow(ConditionSyntaxException::class, "Unexpected '=='")
        ->and(fn () => $parser->parse('1 == 1 )'))->toThrow(ConditionSyntaxException::class, 'Unexpected trailing input')
        ->and(fn () => $parser->parse('1 2'))->toThrow(ConditionSyntaxException::class, "Unexpected operand '2'")
        ->and(fn () => $parser->parse('foo == 1'))->toThrow(ConditionSyntaxException::class, "Unknown identifier 'foo'")
        ->and(fn () => (new AccessorParser())->split('not-an-expr'))->toThrow(ConditionSyntaxException::class, 'Malformed runtime expression');

    ExpressionEvaluatorRegistry::reset();
    CriterionEvaluatorRegistry::reset();
    expect(fn () => ExpressionEvaluatorRegistry::create())->toThrow(RegistryInitializationException::class, 'ExpressionEvaluatorRegistry')
        ->and(fn () => CriterionEvaluatorRegistry::create())->toThrow(RegistryInitializationException::class, 'CriterionEvaluatorRegistry');

    $expr = ExpressionEngine::create();
    $builtIn = new BuiltInCriterionEvaluator($expr, new ConditionEvaluator($expr));
    $ctx = new EvaluationContext(new WorkflowContext('def-1'), 's1');

    // Invalid regex throws CriterionEvaluationException
    expect(fn () => $builtIn->evaluate(new SuccessCriterion('$response.body', '[unclosed', CriterionType::Regex), CriterionType::Regex, 'abc', $ctx))
        ->toThrow(CriterionEvaluationException::class, 'Invalid regular expression');

    // Non-array/object JSONPath root returns false
    expect($builtIn->evaluate(new SuccessCriterion('$response.body', '$.id', CriterionType::JsonPath), CriterionType::JsonPath, 'scalar', $ctx))
        ->toBeFalse();

    // Regex stringify branches: int scalar, assoc array (JSON), list array (CSV), null/object (empty)
    expect($builtIn->evaluate(new SuccessCriterion('$response.body', '^42$', CriterionType::Regex), CriterionType::Regex, 42, $ctx))->toBeTrue()
        ->and($builtIn->evaluate(new SuccessCriterion('$response.body', '"k":"v"', CriterionType::Regex), CriterionType::Regex, ['k' => 'v'], $ctx))->toBeTrue()
        ->and($builtIn->evaluate(new SuccessCriterion('$response.body', '^a,b$', CriterionType::Regex), CriterionType::Regex, ['a', 'b'], $ctx))->toBeTrue()
        ->and($builtIn->evaluate(new SuccessCriterion('$response.body', '^$', CriterionType::Regex), CriterionType::Regex, new stdClass(), $ctx))->toBeTrue();

    // Operation step with no criteria and no recorded statusCode defaults to passing
    $evaluator = new CriteriaEvaluator($expr);
    $httpStep = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'op1');
    expect($evaluator->evaluateSuccessCriteria($httpStep, new WorkflowContext('def-1')))->toBeTrue();
});
