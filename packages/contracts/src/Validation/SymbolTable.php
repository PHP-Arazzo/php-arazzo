<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Workflow;

final readonly class SymbolTable
{
    public KnownOperations $knownOperations;

    /**
     * @var array<string,array<string,true>>
     */
    public array $resolvedOperations;

    /**
     * @param  array<string,WorkflowSymbols>  $workflows
     * @param  array<string,true>  $sourceDescriptions
     * @param  array<string,array<string,true>>  $components
     * @param  KnownOperations|array<string,array<string,true>>  $knownOperations
     * @param  array<string,array<string,true>>  $resolvedOperations
     */
    public function __construct(
        public array $workflows,
        public array $sourceDescriptions,
        public array $components,
        KnownOperations|array $knownOperations = new KnownOperations(),
        array $resolvedOperations = [],
    ) {
        $this->knownOperations = $knownOperations instanceof KnownOperations
            ? $knownOperations
            : new KnownOperations($knownOperations);
        $this->resolvedOperations = $resolvedOperations !== []
            ? $resolvedOperations
            : $this->knownOperations->toArray();
    }

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

        return new self($workflows, $sources, $components, $ops);
    }

    public function hasWorkflow(string $workflowId): bool
    {
        return isset($this->workflows[$workflowId]);
    }

    public function hasSource(string $name): bool
    {
        return isset($this->sourceDescriptions[$name]);
    }

    /**
     * @param  array<array-key,mixed>  $arr
     * @return array<string,true>
     */
    private static function keysOf(array $arr): array
    {
        $out = [];
        foreach (array_keys($arr) as $k) {
            $out[(string) $k] = true;
        }

        return $out;
    }

    private static function buildWorkflow(Workflow $wf): WorkflowSymbols
    {
        $inputs = [];
        $properties = $wf->inputs['properties'] ?? null;
        if (is_array($properties)) {
            foreach (array_keys($properties) as $k) {
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
            foreach (array_keys($s->io->outputs) as $k) {
                $outs[$k] = true;
            }
            $stepDependsOn = [];
            foreach ($s->flow->dependsOn as $d) {
                $stepDependsOn[$d] = true;
            }
            $steps[$s->stepId] = new StepSymbols($outs, (int) $i, $stepDependsOn);
        }

        $outputs = [];
        foreach (array_keys($wf->outputs->all()) as $k) {
            $outputs[$k] = true;
        }

        $dependsOn = [];
        foreach ($wf->dependsOn as $d) {
            $dependsOn[$d] = true;
        }

        return new WorkflowSymbols($inputs, $params, $steps, $outputs, $dependsOn);
    }
}
