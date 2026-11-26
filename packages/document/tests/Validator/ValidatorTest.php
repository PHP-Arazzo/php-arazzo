<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation;

use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
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
use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Document\Document;
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

it('applies validator policy to demote errors to warnings during full validation', function (): void {
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $policy = ValidatorPolicy::default()->withSeverity('r.a', Severity::Warning);

    $validator = new Validator(new RuleSet([new RecordingRule()], $policy));
    $result = $validator->validate($doc);

    expect($result->isValid())->toBeTrue()
        ->and($result->errors)->toHaveCount(0)
        ->and($result->warnings)->toHaveCount(2)
        ->and($result->warnings[0]->code)->toBe('r.a');
});

it('configures validator policy through Document::withValidatorPolicy()', function (): void {
    $policy = ValidatorPolicy::default()->withSeverity('r.a', Severity::Warning);
    $docFacade = Document::create()
        ->withRule(new RecordingRule())
        ->withValidatorPolicy($policy);

    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $result = $docFacade->validate($doc);

    $errorCodes = array_map(fn ($e) => $e->code, $result->errors);
    $warningCodes = array_map(fn ($w) => $w->code, $result->warnings);

    expect($errorCodes)->not->toContain('r.a')
        ->and($warningCodes)->toContain('r.a')
        ->and($warningCodes)->toContain('r.a.warn');
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

it('flags unresolved goto targets in workflow-level successActions and failureActions during full validation', function (): void {
    $actionSuccess = new SuccessGotoAction(
        'go',
        'nope',
        null,
        [],
    );
    $actionFailure = new FailureGotoAction(
        'recover',
        null,
        'ghost-wf',
        [],
    );

    $step = StepFactory::http(
        'one', null,
        new StepFlow(),
        new StepIo(),
        operationId: '$sourceDescriptions.api.getThing',
    );
    $source = new SourceDescription('api', 'https://example.com/openapi.yaml', SourceType::Openapi);
    $wf = new Workflow(
        'wf',
        null,
        null,
        null,
        [],
        [$step],
        [$actionSuccess],
        [$actionFailure],
        [],
        [],
    );
    $doc = new ArazzoDocument('1.1.0', new Info('Repro', null, null, '1'), [$source], [$wf], new Components([], [], [], []), []);

    $validator = new Validator(RuleSet::default());
    $result = $validator->validate($doc);

    expect($result->isValid())->toBeFalse();

    $gotoErrors = array_values(array_filter($result->errors, fn ($e) => $e->code === 'action.goto_target_resolves'));
    expect($gotoErrors)->toHaveCount(2)
        ->and($gotoErrors[0]->path)->toBe('/workflows/0/successActions/0/stepId')
        ->and($gotoErrors[0]->message)->toBe("Action references unknown stepId 'nope'.")
        ->and($gotoErrors[1]->path)->toBe('/workflows/0/failureActions/0/workflowId')
        ->and($gotoErrors[1]->message)->toBe("Action references unknown workflowId 'ghost-wf'.");
});
