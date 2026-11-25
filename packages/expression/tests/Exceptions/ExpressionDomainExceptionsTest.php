<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Expression\Exceptions;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Exceptions\InvalidSelectorPluginException;
use Alama\Arazzo\Expression\Exceptions\SelectorEvaluationException;
use Alama\Arazzo\Expression\ExpressionEvaluator;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use Alama\Arazzo\Expression\Interpolator;
use Alama\Arazzo\Expression\Plugins\ValidationRule\MessageRefRequiresReceiveRule;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;

afterEach(function (): void {
    SelectorPluginRegistry::reset();
});

it('covers expression domain exception factories, ExpressionEvaluator::jsonPointer, and MessageRefRequiresReceiveRule', function (): void {
    $registry = SelectorPluginRegistry::create();

    expect(fn () => $registry->register(ExpressionType::JsonPath, null))
        ->toThrow(InvalidSelectorPluginException::class, 'Plugin must be provided');

    $untypedPlugin = new class() implements SelectorPluginInterface
    {
        public function supports(ExpressionType $type): bool
        {
            return false;
        }

        public function supportedVersions(): array
        {
            return [];
        }

        public function evaluate(mixed $root, string $selector, ?string $version = null, ?array $namespaces = null): mixed
        {
            return null;
        }
    };

    expect(fn () => $registry->register($untypedPlugin))
        ->toThrow(InvalidSelectorPluginException::class, 'Cannot determine ExpressionType');

    expect(fn () => (new Interpolator())->interpolate('{$   }', static fn (): string => 'x'))
        ->toThrow(ExpressionSyntaxException::class, 'Malformed embedded expression');

    $noPlugin = SelectorEvaluationException::noPluginRegistered('custom', '/steps/s1');
    expect($noPlugin->codeId)->toBe('selector.no_plugin')
        ->and($noPlugin->path)->toBe('/steps/s1');

    $wrapped = SelectorEvaluationException::atLocation($noPlugin, '/workflows/w1/steps/s1');
    expect($wrapped->path)->toBe('/workflows/w1/steps/s1')
        ->and($wrapped->getPrevious())->toBe($noPlugin);

    expect((new ExpressionEvaluator())->jsonPointer(['a' => ['b' => 7]], '/a/b'))->toBe(7);

    // MessageRefRequiresReceiveRule: valid receive step, invalid http step, and workflow-level $message
    $recvStep = new Step(
        'recv',
        null,
        StepTarget::async('receive', '/events'),
        new StepFlow(),
        new StepIo(outputs: ['payload' => new Expression('$message.payload#/id')]),
    );
    $httpStep = StepFactory::http(
        'http',
        null,
        new StepFlow(),
        new StepIo(outputs: [
            'ok' => new Expression('$response.body#/id'),
            'bad' => new Expression('$message.payload#/id'),
        ]),
        operationId: 'op1',
    );
    $wf = new Workflow('wf1', null, null, null, [], [$recvStep, $httpStep], [], [], ['wfBad' => new Expression('$message.payload#/id')], []);
    $doc = new ArazzoDocument('1.1.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $rule = new MessageRefRequiresReceiveRule();
    $collector = new ErrorCollector();
    $rule->check($doc, SymbolTable::build($doc), $collector);

    expect($collector->errors())->toHaveCount(2)
        ->and($rule->code())->toBe('expr.message_requires_receive');
});
