<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;

final readonly class ArazzoDocument
{
    /**
     * @param  list<SourceDescription>  $sourceDescriptions
     * @param  list<Workflow>  $workflows
     * @param  array<string,mixed>  $specificationExtensions
     * @param  array<string,mixed>|null  $rawRoot
     */
    public function __construct(
        public string $arazzo,
        public Info $info,
        public array $sourceDescriptions,
        public array $workflows,
        public Components $components,
        public array $specificationExtensions,
        public ?array $rawRoot = null,
        public SpecVersion $specVersion = SpecVersion::V1_0,
        public ?string $self = null,
    ) {}

    public function hasExternalSourceFor(string $workflowId): bool
    {
        return array_any($this->sourceDescriptions, function ($sourceDesc) use ($workflowId) {
            return $sourceDesc->type === SourceType::Arazzo && str_starts_with($workflowId, $sourceDesc->name.'.');
        });
    }

    public function findWorkflow(?string $workflowId): ?Workflow
    {
        if ($workflowId === null) {
            return null;
        }

        return array_find(
            $this->workflows,
            static fn (Workflow $workflow): bool => $workflow->workflowId === $workflowId,
        );
    }

    public function firstWorkflow(): ?Workflow
    {
        return $this->workflows[0] ?? null;
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
