<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\SelfRef;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Plugins\ValidationRule\MessageRefRequiresReceiveRule;
use Alama\Arazzo\Tests\Support\Fx;

function messageRuleDocument(
    SpecVersion $sv,
    string $action,
    array $criteria = [],
    array $outputs = [],
): ArazzoDocument {
    $step = new Step(
        'wait',
        null,
        new StepTarget(action: $action, channelPath: 'channels/x'),
        new StepFlow(),
        new StepIo(successCriteria: $criteria, outputs: $outputs),
    );

    return new ArazzoDocument(
        arazzo: $sv->value,
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [],
        workflows: [new Workflow('w', null, null, null, [], [$step], [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: $sv,
    );
}

function httpRuleDocument(SpecVersion $sv, array $outputs = []): ArazzoDocument
{
    $step = new Step(
        'fetch',
        null,
        new StepTarget(operationId: 'op'),
        new StepFlow(),
        new StepIo(successCriteria: [], outputs: $outputs),
    );

    return new ArazzoDocument(
        arazzo: $sv->value,
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [],
        workflows: [new Workflow('w', null, null, null, [], [$step], [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: $sv,
    );
}

it('allows $message in receive step successCriteria', function (): void {
    $errors = new ErrorCollector();
    $criterion = new SuccessCriterion(null, '$message.payload#/status == 200', CriterionType::Simple);
    $doc = messageRuleDocument(SpecVersion::V1_1, 'receive', [$criterion]);

    (new MessageRefRequiresReceiveRule())->check(
        $doc,
        SymbolTable::build($doc),
        $errors,
    );

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toBe([]);
});

it('allows $message in receive step outputs', function (): void {
    $errors = new ErrorCollector();
    $doc = messageRuleDocument(
        SpecVersion::V1_1,
        'receive',
        [],
        ['orderId' => new Expression('{$message.payload#/orderId}')],
    );

    (new MessageRefRequiresReceiveRule())->check(
        $doc,
        SymbolTable::build($doc),
        $errors,
    );

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toBe([]);
});

it('flags $message in an HTTP step', function (): void {
    $errors = new ErrorCollector();
    $doc = httpRuleDocument(
        SpecVersion::V1_1,
        ['orderId' => new Expression('{$message.payload#/orderId}')],
    );

    (new MessageRefRequiresReceiveRule())->check(
        $doc,
        SymbolTable::build($doc),
        $errors,
    );

    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('expr.message_requires_receive');
});

it('flags $message in a send step', function (): void {
    $errors = new ErrorCollector();
    $doc = messageRuleDocument(
        SpecVersion::V1_1,
        'send',
        [],
        ['orderId' => new Expression('{$message.payload#/orderId}')],
    );

    (new MessageRefRequiresReceiveRule())->check(
        $doc,
        SymbolTable::build($doc),
        $errors,
    );

    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('expr.message_requires_receive');
});

it('flags $message with no step context', function (): void {
    $errors = new ErrorCollector();
    $step = new Step(
        'other',
        null,
        new StepTarget(action: 'receive', channelPath: 'channels/x'),
        new StepFlow(),
        new StepIo(),
    );
    $doc = new ArazzoDocument(
        arazzo: SpecVersion::V1_1->value,
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [],
        workflows: [new Workflow(
            'w', null, null, null, [], [$step], [], [],
            ['m' => new Expression('{$message.payload#/orderId}')], [],
        )],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: SpecVersion::V1_1,
    );
    // Workflow-level output referencing $message has no current step context.

    (new MessageRefRequiresReceiveRule())->check(
        $doc,
        SymbolTable::build($doc),
        $errors,
    );

    expect($errors->errors())->toHaveCount(1);
});

it('skips non-message references', function (): void {
    $step = Fx::step('fetch', 'op', outputs: ['result' => new Expression('{$steps.fetch.outputs.result}')]);
    $workflow = Fx::wf('w', [$step], outputs: ['out' => new Expression('{$steps.fetch.outputs.result}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Step, target: 'fetch');
    $parser = new class($ref) implements ParserInterface
    {
        public function __construct(private readonly ExpressionReference $ref) {}

        public function parse(string $raw): ExpressionAst
        {
            return new SelfRef();
        }

        public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
        {
            return new SelfRef();
        }

        public function projectReferences(string $raw): ?ExpressionReference
        {
            return $this->ref;
        }
    };
    $ec = new ErrorCollector();
    (new MessageRefRequiresReceiveRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('findStep returns null for null stepId', function (): void {
    $wf = new Workflow('w', null, null, null, [], [], [], [], ['out' => new Expression('{$message.payload#/x}')], []);
    $doc = Fx::doc(workflows: [$wf]);
    $ref = new ExpressionReference(kind: ReferenceKind::Message, target: null);
    $parser = new class($ref) implements ParserInterface
    {
        public function __construct(private readonly ExpressionReference $ref) {}

        public function parse(string $raw): ExpressionAst
        {
            return new SelfRef();
        }

        public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
        {
            return new SelfRef();
        }

        public function projectReferences(string $raw): ?ExpressionReference
        {
            return $this->ref;
        }
    };
    $ec = new ErrorCollector();
    (new MessageRefRequiresReceiveRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});
