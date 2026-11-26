<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Dto;

use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\Enum\ActionKind;

it('builds success actions', function (): void {
    $g = new SuccessGotoAction('go', 'step2', null, []);
    $e = new SuccessEndAction('end', []);

    expect($g->kind)->toBe(ActionKind::Goto)
        ->and($g->stepId)->toBe('step2')
        ->and($e->kind)->toBe(ActionKind::End);
});

it('builds failure actions', function (): void {
    $r = new RetryAction('r', 500, 3, 'step1', null, []);
    expect($r->kind)->toBe(ActionKind::Retry)
        ->and($r->retryLimit)->toBe(3);

    $g = new FailureGotoAction('go', null, 'wfB', []);
    $e = new FailureEndAction('end', []);
    expect($g->workflowId)->toBe('wfB')
        ->and($e->kind)->toBe(ActionKind::End);
});

it('distinguishes spec and extension cases on ActionKind and SubWorkflowAction', function (): void {
    expect(ActionKind::Goto->isSpec())->toBeTrue()
        ->and(ActionKind::End->isSpec())->toBeTrue()
        ->and(ActionKind::Retry->isSpec())->toBeTrue()
        ->and(ActionKind::Invoke->isSpec())->toBeFalse()
        ->and(ActionKind::Invoke->isExtension())->toBeTrue()
        ->and(ActionKind::tryFromRaw('goto'))->toBe(ActionKind::Goto)
        ->and(ActionKind::tryFromRaw('x-invoke'))->toBe(ActionKind::Invoke)
        ->and(ActionKind::tryFromRaw('invalid'))->toBeNull();

    $sub = new SubWorkflowSuccessAction(
        'sub',
        'wf-target',
        [],
        [],
        null,
        'x-invoke',
    );
    expect($sub->isInvoke())->toBeTrue()
        ->and($sub->isExtension())->toBeTrue()
        ->and($sub->rawType)->toBe('x-invoke');
});
