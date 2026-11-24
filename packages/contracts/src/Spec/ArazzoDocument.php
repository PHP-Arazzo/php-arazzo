<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;

final readonly class ArazzoDocument
{
    /**
     * @param  list<SourceDescription>|SourceDescriptionCollection  $sourceDescriptions
     * @param  array<Workflow>|WorkflowCollection  $workflows
     * @param  array<string,mixed>  $specificationExtensions
     * @param  array<string,mixed>|null  $rawRoot
     */
    public function __construct(
        public string $arazzo,
        public Info $info,
        array|SourceDescriptionCollection $sourceDescriptions,
        array|WorkflowCollection $workflows,
        public Components $components,
        public array $specificationExtensions,
        public ?array $rawRoot = null,
        public SpecVersion $specVersion = SpecVersion::V1_0,
        public ?string $self = null,
    ) {
        $this->sourceDescriptions = $sourceDescriptions instanceof SourceDescriptionCollection
            ? $sourceDescriptions->all()
            : $sourceDescriptions;
        $this->sourceDescriptionsCollection = $sourceDescriptions instanceof SourceDescriptionCollection
            ? $sourceDescriptions
            : SourceDescriptionCollection::fromArray($sourceDescriptions);
        $this->workflows = $workflows instanceof WorkflowCollection
            ? $workflows
            : WorkflowCollection::fromArray($workflows);
    }

    /** @var list<SourceDescription> */
    public array $sourceDescriptions;

    public SourceDescriptionCollection $sourceDescriptionsCollection;

    public WorkflowCollection $workflows;

    public function hasExternalSourceFor(string $workflowId): bool
    {
        return $this->sourceDescriptionsCollection()->hasExternalSourceFor($workflowId);
    }

    /**
     * Strongly typed accessor to the workflows collection.
     */
    public function workflowsCollection(): WorkflowCollection
    {
        return $this->workflows;
    }

    /**
     * Strongly typed accessor to the source descriptions collection.
     */
    public function sourceDescriptionsCollection(): SourceDescriptionCollection
    {
        return $this->sourceDescriptionsCollection;
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
        return $this->sourceDescriptionsCollection->findSource($name);
    }

    /**
     * @return list<SourceDescription>
     */
    public function nonArazzoSources(): array
    {
        return $this->sourceDescriptionsCollection->nonArazzo()->all();
    }
}
