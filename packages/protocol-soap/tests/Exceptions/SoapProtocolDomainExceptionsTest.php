<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\EngineException;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Soap\Exceptions\InvalidWsdlDocumentException;
use Alama\Arazzo\Protocol\Soap\Exceptions\SoapEnvelopeException;
use Alama\Arazzo\Protocol\Soap\Exceptions\SoapExecutionException;
use Alama\Arazzo\Protocol\Soap\Exceptions\WsdlResolutionException;
use Alama\Arazzo\Protocol\Soap\Execution\SoapRequestBuilder;
use Alama\Arazzo\Protocol\Soap\Transfer\SoapResponseTransfer;
use GuzzleHttp\Psr7\HttpFactory;

test('protocol-soap domain exception factories construct typed exceptions', function (): void {
    $cause = new RuntimeException('boom');

    expect(SoapExecutionException::resolutionFailed('s1', $cause))
        ->toBeInstanceOf(ExecutionException::class)
        ->and(SoapExecutionException::requestBodyMissing('s1')->getMessage())->toContain('application/xml')
        ->and(SoapExecutionException::namespaceMissing('s1')->getMessage())->toContain('targetNamespace')
        ->and(SoapExecutionException::targetEndpointMissing('s1')->getMessage())->toContain('endpoint')
        ->and(WsdlResolutionException::notWsdlStep('s1'))->toBeInstanceOf(EngineException::class)
        ->and(WsdlResolutionException::sourceNotFound('soap')->getMessage())->toContain('soap')
        ->and(WsdlResolutionException::operationNotFound('Add', 'soap')->getMessage())->toContain('Add')
        ->and(InvalidWsdlDocumentException::invalidXml())->toBeInstanceOf(InvalidArgumentException::class)
        ->and(InvalidWsdlDocumentException::notWsdl11()->getMessage())->toContain('WSDL 1.1')
        ->and(SoapEnvelopeException::elementCreationFailed())->toBeInstanceOf(EngineException::class);
});

test('SoapResponseTransfer exposes failure, status, and body domain queries (Tell, Dont Ask)', function (): void {
    $ok = new SoapResponseTransfer(200, ['Content-Type' => 'text/xml'], '<ok/>');
    $fault = new SoapResponseTransfer('fault', [], null, [], ['soap.faultcode' => 'Client']);
    $transport = new SoapResponseTransfer(500, [], '', [], ['error' => 'timeout']);

    expect($ok->hasFailure())->toBeFalse()
        ->and($ok->failureCategory())->toBeNull()
        ->and($ok->httpStatusCode())->toBe(200)
        ->and($ok->rawXmlBody())->toBe('<ok/>')
        ->and($fault->hasFailure())->toBeTrue()
        ->and($fault->failureCategory())->toBe('soap-fault')
        ->and($fault->httpStatusCode())->toBe(500)
        ->and($fault->rawXmlBody())->toBeNull()
        ->and($transport->hasFailure())->toBeTrue()
        ->and($transport->failureCategory())->toBe('transport');
});

test('SoapRequestBuilder throws typed SoapExecutionException on missing metadata', function (): void {
    $httpFactory = new HttpFactory();
    $builder = new SoapRequestBuilder($httpFactory, $httpFactory, new EvaluationEngine(expression: ExpressionEngine::create()));
    $source = new SourceDescription('calc', 'https://calc.test/wsdl', SourceType::Wsdl);
    $doc = new ArazzoDocument('1.0.1', new Info('T', null, null, '1'), [$source], [], new Components([], [], [], []), []);
    $step = new Step('s1', null, StepTarget::wsdl('Add'), new StepFlow(), new StepIo());
    $ctx = new WorkflowContext('def', [], [], [], 'wf', 'exec-1');

    $noBody = new ResolvedOperation($source, new NormalizedOpenApiOperation('Add', 'POST', 'https://calc.test', [], [], [], [], [], []));
    expect(fn () => $builder->build($step, $ctx, $doc, $noBody, 'exec-1'))
        ->toThrow(SoapExecutionException::class, 'application/xml');

    $noNs = new ResolvedOperation($source, new NormalizedOpenApiOperation('Add', 'POST', 'https://calc.test', [], [], [], [], ['application/xml' => ['soapVersion' => '1.1']], []));
    expect(fn () => $builder->build($step, $ctx, $doc, $noNs, 'exec-1'))
        ->toThrow(SoapExecutionException::class, 'targetNamespace');

    $noEndpoint = new ResolvedOperation($source, new NormalizedOpenApiOperation('Add', 'POST', null, [], [], [], [], ['application/xml' => ['namespace' => 'urn:calc']], []));
    expect(fn () => $builder->build($step, $ctx, $doc, $noEndpoint, 'exec-1'))
        ->toThrow(SoapExecutionException::class, 'endpoint');
});
