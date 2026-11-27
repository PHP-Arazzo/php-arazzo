<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ActionCollection;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\CriterionCollection;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Interaction;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OutputMap;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\ParameterCollection;
use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\PendingCorrelation;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDescriptionCollection;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepCollection;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\StringList;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Spec\WorkflowCollection;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use DateTimeImmutable;

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

    $httpStep = StepFactory::http('s1', null, new StepFlow(timeout: 2500, onSuccess: [$okEnd]), new StepIo(), operationId: 'listPets');
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
        ->and($httpStep->hasAction())->toBeFalse()
        ->and($httpStep->isReceiveAction())->toBeFalse()
        ->and($httpStep->isInteraction())->toBeFalse()
        ->and($httpStep->isActorHandoff())->toBeFalse()
        ->and($httpStep->operationReference())->toBe('listPets')
        ->and($httpStep->flow->timeoutSeconds())->toBe(2.5)
        ->and($subStep->flow->timeoutSeconds())->toBeNull()
        ->and($httpStep->effectiveActions(true, $wf))->toBe([$okEnd])
        ->and($httpStep->effectiveActions(false, $wf))->toBe([$failEnd])
        ->and($httpStep->allActions($wf))->toBe([$okEnd, $okEnd, $failEnd])
        ->and($subStep->isSubWorkflow())->toBeTrue()
        ->and($subStep->effectiveActions(true, $wf))->toBe([$okEnd])
        ->and($recvStep->isAsyncAction())->toBeTrue()
        ->and($recvStep->hasAction())->toBeTrue()
        ->and($recvStep->isReceiveAction())->toBeTrue()
        ->and($recvStep->isActorHandoff())->toBeTrue()
        ->and($interactStep->isInteraction())->toBeTrue()
        ->and($interactStep->isActorHandoff())->toBeTrue()
        ->and($wsdlStep->operationReference())->toBe('Add');

    $apiSrc = new SourceDescription('api', 'https://api.test/openapi.json', SourceType::Openapi);
    $arazzoSrc = new SourceDescription('sub', 'https://api.test/sub.yaml', SourceType::Arazzo);
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [$apiSrc, $arazzoSrc], WorkflowCollection::fromArray([$wf, $emptyWf]), $components, []);

    expect($doc->findWorkflow('main'))->toBe($wf)
        ->and($doc->findWorkflow('missing'))->toBeNull()
        ->and($doc->findWorkflow(null))->toBeNull()
        ->and($doc->firstWorkflow())->toBe($wf)
        ->and($doc->findSource('api'))->toBe($apiSrc)
        ->and($doc->findSource('nope'))->toBeNull()
        ->and($doc->nonArazzoSources())->toBe([$apiSrc])
        ->and($doc->hasExternalSourceFor('sub.child'))->toBeTrue()
        ->and($doc->hasExternalSourceFor('main'))->toBeFalse();

    $wsdlSrc = new SourceDescription('soap', 'specs/service.wsdl', SourceType::Wsdl);
    $protoSrc = new SourceDescription('rpc', 'HTTP://rpc.test/svc.proto', SourceType::Protobuf);

    expect($apiSrc->isOpenApi())->toBeTrue()
        ->and($apiSrc->isArazzo())->toBeFalse()
        ->and($apiSrc->scheme())->toBe('https')
        ->and($arazzoSrc->isArazzo())->toBeTrue()
        ->and($wsdlSrc->isWsdl())->toBeTrue()
        ->and($wsdlSrc->scheme())->toBe('file')
        ->and($protoSrc->isProtobuf())->toBeTrue()
        ->and($protoSrc->scheme())->toBe('http');

    $now = new DateTimeImmutable('2026-01-01T00:05:00Z');
    $expired = new PendingCorrelation('c1', 'e1', 's1', '/ch', new DateTimeImmutable('2026-01-01T00:01:00Z'));
    $noExpiry = new PendingCorrelation('c2', 'e1', 's1', '/ch');
    expect($expired->isExpiredAt($now))->toBeTrue()
        ->and($noExpiry->isExpiredAt($now))->toBeFalse();

    expect((new PayloadReplacement('/a', 1, 'xpath'))->selectorType())->toBe('xpath')
        ->and((new PayloadReplacement('/a', 1, ['type' => 'jsonpath']))->selectorType())->toBe('jsonpath')
        ->and((new PayloadReplacement('/a', 1, ['version' => '1']))->selectorType())->toBeNull()
        ->and((new PayloadReplacement('/a', 1))->selectorType())->toBeNull();

    $symbols = SymbolTable::build($doc);
    expect($symbols->hasWorkflow('main'))->toBeTrue()
        ->and($symbols->hasWorkflow('missing'))->toBeFalse()
        ->and($symbols->hasSource('api'))->toBeTrue()
        ->and($symbols->hasSource('missing'))->toBeFalse()
        ->and($symbols->workflows['main']->hasStep('s1'))->toBeTrue()
        ->and($symbols->workflows['main']->hasInput('none'))->toBeFalse()
        ->and($symbols->workflows['main']->hasOutput('none'))->toBeFalse();
});

it('tests rich methods on Action, Parameter, Criterion, Step, and Collection DTOs', function (): void {
    $endAction = new SuccessEndAction('end', []);
    $gotoAction = new SuccessGotoAction('goto', 's2', null, []);
    $wfGotoAction = new FailureGotoAction('wfgoto', null, 'wf2', []);
    $retryAction = new RetryAction('retry', 2.0, 3, 's1', null, []);
    $invokeAction = new SubWorkflowSuccessAction('inv', 'childWf', [], []);

    expect($endAction->isEnd())->toBeTrue()
        ->and($endAction->isGoto())->toBeFalse()
        ->and($endAction->hasCriteria())->toBeFalse()
        ->and($gotoAction->isGoto())->toBeTrue()
        ->and($gotoAction->isStepTarget())->toBeTrue()
        ->and($gotoAction->isWorkflowTarget())->toBeFalse()
        ->and($wfGotoAction->isStepTarget())->toBeFalse()
        ->and($wfGotoAction->isWorkflowTarget())->toBeTrue()
        ->and($retryAction->isRetry())->toBeTrue()
        ->and($retryAction->isStepTarget())->toBeTrue()
        ->and($retryAction->hasRetryLimit())->toBeTrue()
        ->and($retryAction->hasRetryAfter())->toBeTrue()
        ->and($invokeAction->isInvoke())->toBeTrue();

    $qParam = new Parameter('q', ParameterIn::Query, 'search');
    $hParam = new Parameter('auth', ParameterIn::Header, new Expression('$inputs.tok'));
    $pParam = new Parameter('id', ParameterIn::Path, 42);
    $bParam = new Parameter('body', ParameterIn::Body, []);
    $cParam = new Parameter('sess', ParameterIn::Cookie, 'c1');
    $mParam = new Parameter('meta', ParameterIn::Metadata, 'm1');
    $qsParam = new Parameter('qs', ParameterIn::Querystring, 'a=1');
    $vParam = new Parameter('v', ParameterIn::Variable, 'var');

    expect($qParam->isQuery())->toBeTrue()
        ->and($hParam->isHeader())->toBeTrue()
        ->and($hParam->isExpression())->toBeTrue()
        ->and($hParam->isSelector())->toBeFalse()
        ->and($pParam->isPath())->toBeTrue()
        ->and($bParam->isBody())->toBeTrue()
        ->and($cParam->isCookie())->toBeTrue()
        ->and($mParam->isMetadata())->toBeTrue()
        ->and($qsParam->isQuerystring())->toBeTrue()
        ->and($vParam->isVariable())->toBeTrue();

    $paramCol = new ParameterCollection([$qParam, $hParam]);
    expect($paramCol->has('q'))->toBeTrue()
        ->and($paramCol->has('missing'))->toBeFalse()
        ->and($paramCol->findByName('q'))->toBe($qParam)
        ->and($paramCol->findByName('missing'))->toBeNull()
        ->and($paramCol->names())->toBe(['q', 'auth'])
        ->and($paramCol->forLocation(ParameterIn::Query)->count())->toBe(1)
        ->and($paramCol->forLocation(ParameterIn::Path)->count())->toBe(0);

    $critSimple = new SuccessCriterion('$statusCode == 200', 'true', CriterionType::Simple, '1.0');
    $critRegex = new SuccessCriterion('$response.body', '/ok/', CriterionType::Regex);
    $critJsonPath = new SuccessCriterion(null, '$.id', CriterionType::JsonPath);
    $critXPath = new SuccessCriterion(null, '//item', CriterionType::XPath);

    expect($critSimple->isSimple())->toBeTrue()
        ->and($critSimple->hasContext())->toBeTrue()
        ->and($critSimple->hasVersion())->toBeTrue()
        ->and($critRegex->isRegex())->toBeTrue()
        ->and($critJsonPath->isJsonPath())->toBeTrue()
        ->and($critJsonPath->hasContext())->toBeFalse()
        ->and($critXPath->isXPath())->toBeTrue();

    $reqBody = new RequestBody('application/json', ['x' => 1], []);
    $stepIo = new StepIo(
        parameters: [$qParam],
        requestBody: $reqBody,
        successCriteria: [$critSimple],
        outputs: ['res' => '$response.body'],
    );

    expect($stepIo->hasParameters())->toBeTrue()
        ->and($stepIo->hasOutputs())->toBeTrue()
        ->and($stepIo->hasSuccessCriteria())->toBeTrue()
        ->and($stepIo->hasRequestBody())->toBeTrue()
        ->and($stepIo->findParameter('q'))->toBe($qParam)
        ->and($stepIo->hasParameter('q'))->toBeTrue()
        ->and($stepIo->hasParameter('missing'))->toBeFalse();

    $stepFlow = new StepFlow(
        dependsOn: ['s0'],
        timeout: 5000,
        onSuccess: [$endAction],
        onFailure: [$retryAction],
    );

    expect($stepFlow->hasDependsOn())->toBeTrue()
        ->and($stepFlow->hasTimeout())->toBeTrue()
        ->and($stepFlow->hasSuccessActions())->toBeTrue()
        ->and($stepFlow->hasFailureActions())->toBeTrue();

    $step = new Step('testStep', 'A step', StepTarget::http('getOp'), $stepFlow, $stepIo);

    expect($step->parameters())->toBe([$qParam])
        ->and($step->outputs())->toBe(['res' => '$response.body'])
        ->and($step->successCriteria())->toBe([$critSimple])
        ->and($step->requestBody())->toBe($reqBody)
        ->and($step->hasOutputs())->toBeTrue()
        ->and($step->hasParameters())->toBeTrue()
        ->and($step->hasSuccessCriteria())->toBeTrue()
        ->and($step->hasRequestBody())->toBeTrue()
        ->and($step->dependsOn())->toBe(['s0'])
        ->and($step->flow->timeoutSeconds())->toBe(5.0);

    $stepTarget = StepTarget::async('send', '/channel');
    expect($stepTarget->isAsync())->toBeTrue()
        ->and($stepTarget->isSend())->toBeTrue()
        ->and($stepTarget->isReceive())->toBeFalse()
        ->and($stepTarget->isHttp())->toBeFalse()
        ->and($stepTarget->isWorkflow())->toBeFalse();

    $outputMap = new OutputMap(['id' => '1', 'name' => 'test']);
    expect($outputMap->names())->toBe(['id', 'name']);

    $stepCol = new StepCollection([$step]);
    expect($stepCol->hasStep('testStep'))->toBeTrue()
        ->and($stepCol->hasStep('missing'))->toBeFalse()
        ->and($stepCol->stepIds())->toBe(['testStep']);

    $components = new Components([], ['param1' => $qParam], ['act1' => $endAction], ['act2' => $retryAction], ['int1' => new Interaction()]);
    expect($components->hasParameter('param1'))->toBeTrue()
        ->and($components->hasParameter('nope'))->toBeFalse()
        ->and($components->hasAction('act1'))->toBeTrue()
        ->and($components->hasAction('act2'))->toBeTrue()
        ->and($components->hasAction('nope'))->toBeFalse()
        ->and($components->isEmpty())->toBeFalse()
        ->and($components->resolveReusableParameter(new Reusable('$components.parameters.param1')))->toBe($qParam)
        ->and($components->resolveReusableParameter(new Reusable('$components.parameters.nope')))->toBeNull();

    $emptyComp = new Components([], [], [], [], []);
    expect($emptyComp->isEmpty())->toBeTrue();

    $localSrc = new SourceDescription('local', 'file://path/spec.json', SourceType::Openapi);
    $remoteSrc = new SourceDescription('remote', 'https://api.test/spec.json', SourceType::Openapi);
    expect($localSrc->isLocal())->toBeTrue()
        ->and($localSrc->isRemote())->toBeFalse()
        ->and($remoteSrc->isLocal())->toBeFalse()
        ->and($remoteSrc->isRemote())->toBeTrue();

    $resOp = new ResolvedOperation($remoteSrc, new NormalizedOpenApiOperation(path: '/test', method: 'get'));
    expect($resOp->isHttp())->toBeTrue()
        ->and($resOp->isRpc())->toBeFalse()
        ->and($resOp->isSoap())->toBeFalse()
        ->and($resOp->isGraphql())->toBeFalse()
        ->and($resOp->isInteraction())->toBeFalse();
});

it('tests SourceDescriptionCollection, CriterionCollection, and collection constructors on StepIo, StepFlow, and ArazzoDocument', function (): void {
    $apiSrc = new SourceDescription('api', 'https://api.example.com/openapi.json', SourceType::Openapi);
    $arazzoSrc = new SourceDescription('subWf', './sub.arazzo.yaml', SourceType::Arazzo);
    $wsdlSrc = new SourceDescription('soap', './service.wsdl', SourceType::Wsdl);

    $srcCol = SourceDescriptionCollection::fromArray([$apiSrc, $arazzoSrc, $wsdlSrc]);
    $emptySrcCol = SourceDescriptionCollection::empty();

    expect($srcCol->count())->toBe(3)
        ->and($srcCol->isEmpty())->toBeFalse()
        ->and($emptySrcCol->count())->toBe(0)
        ->and($emptySrcCol->isEmpty())->toBeTrue()
        ->and($srcCol->all())->toBe([$apiSrc, $arazzoSrc, $wsdlSrc])
        ->and($srcCol->findSource('api'))->toBe($apiSrc)
        ->and($srcCol->findSource('nope'))->toBeNull()
        ->and($srcCol->hasSource('api'))->toBeTrue()
        ->and($srcCol->hasSource('nope'))->toBeFalse()
        ->and($srcCol->names())->toBe(['api', 'subWf', 'soap'])
        ->and($srcCol->hasExternalSourceFor('subWf.step1'))->toBeTrue()
        ->and($srcCol->hasExternalSourceFor('api.step1'))->toBeFalse()
        ->and($srcCol->hasExternalSourceFor('other.step1'))->toBeFalse();

    $nonArazzo = $srcCol->nonArazzo();
    expect($nonArazzo->count())->toBe(2)
        ->and($nonArazzo->names())->toBe(['api', 'soap']);

    $iteratedSources = [];
    foreach ($srcCol as $source) {
        $iteratedSources[] = $source->name;
    }
    expect($iteratedSources)->toBe(['api', 'subWf', 'soap']);

    // CriterionCollection
    $cSimple = new SuccessCriterion('$statusCode == 200', 'true', CriterionType::Simple);
    $cRegex = new SuccessCriterion('$response.body', '/ok/', CriterionType::Regex);
    $cJsonPath = new SuccessCriterion(null, '$.id', CriterionType::JsonPath);
    $cXPath = new SuccessCriterion(null, '//item', CriterionType::XPath);

    $critCol = CriterionCollection::fromArray([$cSimple, $cRegex, $cJsonPath, $cXPath]);
    $emptyCritCol = CriterionCollection::empty();

    expect($critCol->count())->toBe(4)
        ->and($critCol->isEmpty())->toBeFalse()
        ->and($emptyCritCol->count())->toBe(0)
        ->and($emptyCritCol->isEmpty())->toBeTrue()
        ->and($critCol->all())->toBe([$cSimple, $cRegex, $cJsonPath, $cXPath])
        ->and($critCol->simpleCriteria()->all())->toBe([$cSimple])
        ->and($critCol->regexCriteria()->all())->toBe([$cRegex])
        ->and($critCol->jsonPathCriteria()->all())->toBe([$cJsonPath])
        ->and($critCol->xpathCriteria()->all())->toBe([$cXPath]);

    $iteratedCriteria = [];
    foreach ($critCol as $crit) {
        $iteratedCriteria[] = $crit->type?->value;
    }
    expect($iteratedCriteria)->toBe(['simple', 'regex', 'jsonpath', 'xpath']);

    // StepIo with collections
    $param = new Parameter('limit', ParameterIn::Query, 10);
    $paramCol = ParameterCollection::fromArray([$param]);
    $outputMap = OutputMap::fromArray(['token' => '$response.body.token']);

    $stepIo = new StepIo(
        parameters: $paramCol,
        requestBody: null,
        successCriteria: $critCol,
        outputs: $outputMap,
    );

    expect($stepIo->parameters)->toBe([$param])
        ->and($stepIo->parameterCollection)->toBe($paramCol)
        ->and($stepIo->successCriteria)->toBe([$cSimple, $cRegex, $cJsonPath, $cXPath])
        ->and($stepIo->criteriaCollection)->toBe($critCol)
        ->and($stepIo->outputs)->toBe(['token' => '$response.body.token'])
        ->and($stepIo->outputMap)->toBe($outputMap)
        ->and($stepIo->findParameter('limit'))->toBe($param)
        ->and($stepIo->hasParameter('limit'))->toBeTrue();

    // StepFlow with collections
    $depList = StringList::fromArray(['s0', 's1']);
    $successAction = new SuccessEndAction('ok', []);
    $failAction = new FailureEndAction('fail', []);
    $actColSuccess = ActionCollection::fromArray([$successAction]);
    $actColFail = ActionCollection::fromArray([$failAction]);

    $stepFlow = new StepFlow(
        dependsOn: $depList,
        timeout: 3000,
        onSuccess: $actColSuccess,
        onFailure: $actColFail,
        onTimeout: $actColFail,
        onCancel: $actColFail,
    );

    expect($stepFlow->dependsOn)->toBe(['s0', 's1'])
        ->and($stepFlow->dependsOnList)->toBe($depList)
        ->and($stepFlow->onSuccess)->toBe([$successAction])
        ->and($stepFlow->successActions)->toBe($actColSuccess)
        ->and($stepFlow->onFailure)->toBe([$failAction])
        ->and($stepFlow->failureActions)->toBe($actColFail)
        ->and($stepFlow->onTimeout)->toBe([$failAction])
        ->and($stepFlow->timeoutActions)->toBe($actColFail)
        ->and($stepFlow->onCancel)->toBe([$failAction])
        ->and($stepFlow->cancelActions)->toBe($actColFail);

    // ArazzoDocument with SourceDescriptionCollection
    $wf = new Workflow('w1', null, null, null, [], [], [], [], [], []);
    $doc = new ArazzoDocument(
        '1.0.0',
        new Info('Test', null, null, '1.0'),
        $srcCol,
        [$wf],
        new Components([], [], [], [], []),
        [],
    );

    expect($doc->sourceDescriptions)->toBe([$apiSrc, $arazzoSrc, $wsdlSrc])
        ->and($doc->sourceDescriptionsCollection)->toBe($srcCol)
        ->and($doc->sourceDescriptionsCollection())->toBe($srcCol)
        ->and($doc->findSource('api'))->toBe($apiSrc)
        ->and($doc->findSource('missing'))->toBeNull()
        ->and($doc->hasExternalSourceFor('subWf.step1'))->toBeTrue()
        ->and($doc->hasExternalSourceFor('other.step1'))->toBeFalse()
        ->and($doc->nonArazzoSources())->toBe([$apiSrc, $wsdlSrc]);
});
