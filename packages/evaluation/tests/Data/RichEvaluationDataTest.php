<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Condition\Token;
use Alama\Arazzo\Evaluation\Data\CriterionResult;
use Alama\Arazzo\Evaluation\Data\CriterionResultCollection;
use Alama\Arazzo\Evaluation\Enum\CriterionOutcome;
use Alama\Arazzo\Evaluation\Enum\CriterionReason;
use Alama\Arazzo\Evaluation\Enum\TokenKind;

test('evaluation Data objects host domain predicates directly (Tell, Dont Ask)', function (): void {
    $criterion = new SuccessCriterion(context: null, condition: '$statusCode == 200', type: null);
    $passed = new CriterionResult($criterion, CriterionOutcome::Passed, CriterionReason::Evaluated);
    $failed = new CriterionResult($criterion, CriterionOutcome::Failed, CriterionReason::Evaluated);
    $errored = new CriterionResult($criterion, CriterionOutcome::Error, CriterionReason::SyntaxError, 'boom');

    $col = new CriterionResultCollection([$passed]);

    expect($passed->isPassed())->toBeTrue()
        ->and($passed->isFailed())->toBeFalse()
        ->and($passed->isError())->toBeFalse()
        ->and($failed->isFailed())->toBeTrue()
        ->and($errored->isError())->toBeTrue()
        ->and((new CriterionResultCollection([]))->criteriaMet())->toBeTrue()
        ->and($col->criteriaMet())->toBeTrue()
        ->and($col->count())->toBe(1)
        ->and(iterator_to_array($col->getIterator()))->toBe([$passed])
        ->and((new CriterionResultCollection([$passed, $failed]))->criteriaMet())->toBeFalse();

    $num = new Token(TokenKind::Number, '200', 0);
    $lparen = new Token(TokenKind::LParen, '(', 0);

    expect($num->isKind(TokenKind::Number))->toBeTrue()
        ->and($num->isTerminal())->toBeTrue()
        ->and($lparen->isKind(TokenKind::LParen))->toBeTrue()
        ->and($lparen->isTerminal())->toBeFalse();
});
