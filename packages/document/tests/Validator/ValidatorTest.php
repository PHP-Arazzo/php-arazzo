<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation;

use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;

class RecordingRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'r.a';
    }

    public function check(ArazzoDocument $d, SymbolTable $s, ErrorCollector $e): void
    {
        $e->error('r.a', 'msg', '/foo');
        $e->warning('r.a.warn', 'wmsg', '/bar');
    }
}

it('collects errors and warnings', function (): void {
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $result = (new Validator(new RuleSet([new RecordingRule()])))->validate($doc);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe('r.a')
        ->and($result->warnings)->toHaveCount(1);
});

it('flags unresolved expressions in action criteria and parameters during full validation', function (): void {
    $actionCriteria = new SuccessEndAction(
        'goEnd',
        [new SuccessCriterion(null, '$steps.unknown.outputs.x == 1', null)],
    );
    $actionParams = new SuccessGotoAction(
        'goGoto',
        null,
        'wf',
        [],
        [new Parameter('x', null, new Expression('{$inputs.missing}'))],
    );

    $step = StepFactory::http(
        'one', null,
        new StepFlow(onSuccess: [$actionCriteria, $actionParams]),
        new StepIo(),
        operationId: '$sourceDescriptions.api.getThing',
    );
    $source = new SourceDescription('api', 'https://example.com/openapi.yaml', SourceType::Openapi);
    $wf = new Workflow('wf', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.1.0', new Info('Repro', null, null, '1'), [$source], [$wf], new Components([], [], [], []), []);

    $validator = new Validator(RuleSet::default());
    $result = $validator->validate($doc);

    expect($result->isValid())->toBeFalse();

    $codes = array_map(fn ($e) => $e->code, $result->errors);
    expect($codes)->toContain('expr.unresolved_step_ref')
        ->toContain('expr.unresolved_input_ref');

    $stepErr = current(array_filter($result->errors, fn ($e) => $e->code === 'expr.unresolved_step_ref'));
    expect($stepErr->path)->toBe('/workflows/0/steps/0/onSuccess/0/criteria/0/condition');

    $inputErr = current(array_filter($result->errors, fn ($e) => $e->code === 'expr.unresolved_input_ref'));
    expect($inputErr->path)->toBe('/workflows/0/steps/0/onSuccess/1/parameters/0/value');
});
