<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;

final readonly class ArazzoDocument
{
    /**
     * @param  list<SourceDescription>  $sourceDescriptions
     * @param  array<Workflow>|WorkflowCollection  $workflows
     * @param  array<string,mixed>  $specificationExtensions
     * @param  array<string,mixed>|null  $rawRoot
     */
    public function __construct(
        public string $arazzo,
        public Info $info,
        public array $sourceDescriptions,
        array|WorkflowCollection $workflows,
        public Components $components,
        public array $specificationExtensions,
        public ?array $rawRoot = null,
        public SpecVersion $specVersion = SpecVersion::V1_0,
        public ?string $self = null,
    ) {
        $this->workflows = $workflows instanceof WorkflowCollection
            ? $workflows
            : WorkflowCollection::fromArray($workflows);
    }

    public WorkflowCollection $workflows;

    public function hasExternalSourceFor(string $workflowId): bool
    {
        return array_any($this->sourceDescriptions, function ($sourceDesc) use ($workflowId) {
            return $sourceDesc->type === SourceType::Arazzo && str_starts_with($workflowId, $sourceDesc->name.'.');
        });
    }

    /**
     * Strongly typed accessor to the workflows collection.
     */
    public function workflowsCollection(): WorkflowCollection
    {
        return $this->workflows;
    }

    public function findWorkflow(?string $workflowId): ?Workflow
    {
        if ($workflowId === null) {
            return null;
        }

        return $this->workflows->findWorkflow($workflowId);
    }

    public function firstWorkflow(): ?Workflow
    {
        return $this->workflows->firstWorkflow();
    }

    public function findSource(string $name): ?SourceDescription
    {
        return array_find(
            $this->sourceDescriptions,
            static fn (SourceDescription $source): bool => $source->name === $name,
        );
    }

    /**
     * @return list<SourceDescription>
     */
    public function nonArazzoSources(): array
    {
        return array_values(array_filter(
            $this->sourceDescriptions,
            static fn (SourceDescription $source): bool => $source->type !== SourceType::Arazzo,
        ));
    }
}
