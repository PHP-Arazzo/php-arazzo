<?php

declare(strict_types=1);

use Alama\Arazzo\Cli\Renderer\Renderer;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Document;

const RENDER_ACTIONS = __DIR__.'/../fixtures/renderer/actions.yaml';

it('renders a mermaid flowchart with retry and goto edges', function (): void {
    $document = Document::create()->load(RENDER_ACTIONS);
    $mermaid = (new Renderer())->toMermaid($document);

    expect($mermaid)->toStartWith('flowchart TD')
        ->and($mermaid)->toContain('subgraph id_flow["workflow: flow"]')
        ->and($mermaid)->toContain('n1["fetch')
        ->and($mermaid)->toContain('-.->|✘ retry| n1')
        ->and($mermaid)->toContain('-.->|✘ goto| n2')
        // explicit end action terminates the chart instead of chaining
        ->and($mermaid)->toContain('n2 --> done')
        ->and($mermaid)->not->toContain('n2 --> n3');
});

it('can scope the mermaid chart to one workflow', function (): void {
    $document = Document::create()->load(RENDER_ACTIONS);
    $mermaid = (new Renderer())->toMermaid($document, 'flow');

    expect($mermaid)->toContain('id_flow')
        ->and($mermaid)->not->toContain('id_other');
});

it('renders markdown docs with sources, steps, criteria, and outputs', function (): void {
    $document = Document::create()->load(RENDER_ACTIONS);
    $markdown = (new Renderer())->toMarkdown($document);

    expect($markdown)->toStartWith('# Render demo')
        ->and($markdown)->toContain('| api | openapi | https://api.test/openapi.json |')
        ->and($markdown)->toContain('## Workflow `flow`')
        ->and($markdown)->toContain('`${response.statusCode} == 200`') // condition text
        ->and($markdown)->toContain('`item` = `$response.body#/item`')
        ->and($markdown)->toContain('**Workflow outputs:**');
});

it('renders document descriptions, workflow summaries/descriptions, empty workflows, all target kinds, literal outputs, and handles Reusable edges and unknown mermaid workflow scopes', function (): void {
    $subStep = new Step('call-sub', null, new StepTarget(workflowId: 'child'), new StepFlow(onSuccess: [new Reusable('$components.successActions.done')]), new StepIo(outputs: ['literal' => 42]));
    $actStep = new Step('recv', null, new StepTarget(action: 'receive'), new StepFlow(), new StepIo());
    $blankStep = new Step('empty-target', null, new StepTarget(), new StepFlow(), new StepIo());

    $wfRich = new Workflow('rich', 'Rich summary', 'Rich description', null, [], [$subStep, $actStep, $blankStep], [], [], ['total' => 7], []);
    $wfEmpty = new Workflow('empty', null, null, null, [], [], [], [], [], []);

    $doc = new ArazzoDocument(
        '1.1.0',
        new Info('Full Doc', null, 'Top-level description', '2.0.0'),
        [],
        [$wfRich, $wfEmpty],
        new Components([], [], [], []),
        [],
    );

    $renderer = new Renderer();
    $markdown = $renderer->toMarkdown($doc);

    expect($markdown)->toContain('Top-level description')
        ->and($markdown)->toContain('_Rich summary_')
        ->and($markdown)->toContain('Rich description')
        ->and($markdown)->toContain('`→ child`')
        ->and($markdown)->toContain('`[receive]`')
        ->and($markdown)->toContain('| — |')
        ->and($markdown)->toContain('`literal` = `42`')
        ->and($markdown)->toContain('- `total` = `7`')
        ->and($markdown)->toContain('_no steps_');

    expect($renderer->toMermaid($doc))->toContain('→ child')
        ->and($renderer->toMermaid($doc, 'unknown-wf'))->toBe("flowchart TD\n");
});
