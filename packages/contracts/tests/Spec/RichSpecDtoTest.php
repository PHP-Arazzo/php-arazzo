<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;

it('queries workflows, sources, steps, components, and step predicates directly on domain DTOs', function (): void {
    $okEnd = new SuccessEndAction('done', []);
    $failEnd = new FailureEndAction('abort', []);
    $param = new Parameter('limit', ParameterIn::Query, 10);
    $interaction = new Interaction('approve', 'user');

    $components = new Components(
        inputs: [],
        parameters: ['limit' => $param],
        successActions: ['done' => $okEnd],
        failureActions: ['abort' => $failEnd],
        interactions: ['approve' => $interaction],
    );

    expect($components->findParameter('limit'))->toBe($param)
        ->and($components->findParameter('missing'))->toBeNull()
        ->and($components->findSuccessAction('done'))->toBe($okEnd)
        ->and($components->findFailureAction('abort'))->toBe($failEnd)
        ->and($components->findInteraction('approve'))->toBe($interaction)
        ->and($components->resolveReusableAction(new Reusable('$components.successActions.done')))->toBe($okEnd)
        ->and($components->resolveReusableAction(new Reusable('$components.failureActions.abort')))->toBe($failEnd)
        ->and($components->resolveReusableAction(new Reusable('$components.successActions.nope')))->toBeNull();

    $httpStep = StepFactory::http('s1', null, new StepFlow(onSuccess: [$okEnd]), new StepIo(), operationId: 'listPets');
    $subStep = StepFactory::workflow('s2', null, new StepFlow(), new StepIo(), workflowId: 'child');
    $recvStep = new Step('s3', null, StepTarget::async('receive', '/events'), new StepFlow(), new StepIo());
    $interactStep = new Step('s4', null, StepTarget::interaction($interaction), new StepFlow(), new StepIo());
    $wsdlStep = new Step('s5', null, StepTarget::wsdl('Add'), new StepFlow(), new StepIo());

    $wf = new Workflow('main', null, null, null, [], [$httpStep, $subStep, $recvStep, $interactStep, $wsdlStep], [$okEnd], [$failEnd], [], []);
    $emptyWf = new Workflow('empty', null, null, null, [], [], [], [], [], []);

    expect($wf->findStep('s2'))->toBe($subStep)
        ->and($wf->findStep('nope'))->toBeNull()
        ->and($wf->firstStep())->toBe($httpStep)
        ->and($emptyWf->firstStep())->toBeNull();

    expect($httpStep->isHttpOperation())->toBeTrue()
        ->and($httpStep->isSubWorkflow())->toBeFalse()
        ->and($httpStep->isAsyncAction())->toBeFalse()
        ->and($httpStep->isReceiveAction())->toBeFalse()
        ->and($httpStep->isInteraction())->toBeFalse()
        ->and($httpStep->isActorHandoff())->toBeFalse()
        ->and($httpStep->operationReference())->toBe('listPets')
        ->and($httpStep->effectiveActions(true, $wf))->toBe([$okEnd])
        ->and($httpStep->effectiveActions(false, $wf))->toBe([$failEnd])
        ->and($subStep->isSubWorkflow())->toBeTrue()
        ->and($subStep->effectiveActions(true, $wf))->toBe([$okEnd])
        ->and($recvStep->isAsyncAction())->toBeTrue()
        ->and($recvStep->isReceiveAction())->toBeTrue()
        ->and($recvStep->isActorHandoff())->toBeTrue()
        ->and($interactStep->isInteraction())->toBeTrue()
        ->and($interactStep->isActorHandoff())->toBeTrue()
        ->and($wsdlStep->operationReference())->toBe('Add');

    $apiSrc = new SourceDescription('api', 'https://api.test/openapi.json', SourceType::Openapi);
    $arazzoSrc = new SourceDescription('sub', 'https://api.test/sub.yaml', SourceType::Arazzo);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [$apiSrc, $arazzoSrc], [$wf, $emptyWf], $components, []);

    expect($doc->findWorkflow('main'))->toBe($wf)
        ->and($doc->findWorkflow('missing'))->toBeNull()
        ->and($doc->findWorkflow(null))->toBeNull()
        ->and($doc->firstWorkflow())->toBe($wf)
        ->and($doc->findSource('api'))->toBe($apiSrc)
        ->and($doc->findSource('nope'))->toBeNull()
        ->and($doc->nonArazzoSources())->toBe([$apiSrc])
        ->and($doc->hasExternalSourceFor('sub.child'))->toBeTrue()
        ->and($doc->hasExternalSourceFor('main'))->toBeFalse();
});
