<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Data;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\KnownOperations;

final readonly class SymbolTable
{
    /**
     * @param  array<string,WorkflowSymbols>  $workflows
     * @param  array<string,true>  $sourceDescriptions
     * @param  array<string,array<string,true>>  $components
     * @param  array<string,array<string,true>>  $resolvedOperations
     */
    public function __construct(
        public array $workflows,
        public array $sourceDescriptions,
        public array $components,
        public KnownOperations $knownOperations = new KnownOperations(),
        public array $resolvedOperations = [],
    ) {}

    /**
     * @param  KnownOperations|array<string,array<string,true>>|null  $resolvedOperations
     */
    public static function build(ArazzoDocument $doc, KnownOperations|array|null $resolvedOperations = null): self
    {
        $sources = [];
        foreach ($doc->sourceDescriptions as $s) {
            $sources[$s->name] = true;
        }

        $components = [
            'inputs' => self::keysOf($doc->components->inputs),
            'parameters' => self::keysOf($doc->components->parameters),
            'successActions' => self::keysOf($doc->components->successActions),
            'failureActions' => self::keysOf($doc->components->failureActions),
        ];

        $workflows = [];
        foreach ($doc->workflows as $wf) {
            $workflows[$wf->workflowId] = self::buildWorkflow($wf);
        }

        $ops = match (true) {
            $resolvedOperations instanceof KnownOperations => $resolvedOperations,
            is_array($resolvedOperations) => new KnownOperations($resolvedOperations),
            default => KnownOperations::none(),
        };

        return new self($workflows, $sources, $components, $ops, $ops->toArray());
    }

    /**
     * @param  array<array-key,mixed>  $arr
     * @return array<string,true>
     */
    private static function keysOf(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $_) {
            $out[(string) $k] = true;
        }

        return $out;
    }

    private static function buildWorkflow(Workflow $wf): WorkflowSymbols
    {
        $inputs = [];
        $properties = $wf->inputs['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $k => $_) {
                $inputs[(string) $k] = true;
            }
        }

        $params = [];
        foreach ($wf->parameters as $p) {
            // Reusable parameters carry no name of their own.
            if ($p instanceof Parameter) {
                $params[$p->name] = true;
            }
        }

        $steps = [];
        foreach ($wf->steps as $i => $s) {
            $outs = [];
            foreach ($s->io->outputs as $k => $_) {
                $outs[$k] = true;
            }
            $stepDependsOn = [];
            foreach ($s->flow->dependsOn as $d) {
                $stepDependsOn[$d] = true;
            }
            $steps[$s->stepId] = new StepSymbols($outs, (int) $i, $stepDependsOn);
        }

        $outputs = [];
        foreach ($wf->outputs as $k => $_) {
            $outputs[$k] = true;
        }

        $dependsOn = [];
        foreach ($wf->dependsOn as $d) {
            $dependsOn[$d] = true;
        }

        return new WorkflowSymbols($inputs, $params, $steps, $outputs, $dependsOn);
    }
}
