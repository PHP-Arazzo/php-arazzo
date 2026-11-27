<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Dependency;

use Alama\Arazzo\Contracts\Spec\Step;

/**
 * @internal stays out of the advertised contract; used internally by the contracts package.
 */
class DependencyGraph
{
    /** @var array<string, Step> */
    private array $stepsById = [];

    /** @var string[] */
    private array $topologicalOrder = [];

    /** @var string[]|null */
    private ?array $cycle = null;

    /** @var array<string, string[]> */
    private array $unresolvedReferences = [];

    /** @var array<string, list<string>> explicit dependsOn + implicit output-reference deps */
    private array $effectiveDependencies = [];

    /**
     * @param  Step[]  $steps
     */
    public function __construct(array $steps)
    {
        foreach ($steps as $step) {
            $this->stepsById[$step->stepId] = $step;
        }

        $this->analyze();
    }

    private function analyze(): void
    {
        $this->buildEffectiveDependencies();

        /** @var array<string, int> $color 0=white,1=grey,2=black */
        $color = array_fill_keys(array_keys($this->stepsById), 0);
        /** @var list<string> $path */
        $path = [];

        foreach (array_keys($this->stepsById) as $id) {
            if ($color[$id] === 0 && $this->visitNode($id, $color, $path)) {
                break;
            }
        }
    }

    private function buildEffectiveDependencies(): void
    {
        foreach ($this->stepsById as $id => $step) {
            $deps = $step->flow->dependsOn;
            foreach (ImplicitDependencies::fromStep($step) as $implicit) {
                if (!in_array($implicit, $deps, true)) {
                    $deps[] = $implicit;
                }
            }
            $this->effectiveDependencies[$id] = $deps;
        }
    }

    /**
     * Depth-first traversal returning true as soon as a cycle is recorded.
     *
     * @param  array<string, int>  $color
     * @param  list<string>  $path
     */
    private function visitNode(string $node, array &$color, array &$path): bool
    {
        $color[$node] = 1;
        $path[] = $node;

        foreach ($this->effectiveDependencies[$node] ?? [] as $next) {
            if ($this->visitEdge($node, $next, $color, $path)) {
                return true;
            }
        }

        $color[$node] = 2;
        array_pop($path);
        $this->topologicalOrder[] = $node;

        return false;
    }

    /**
     * @param  array<string, int>  $color
     * @param  list<string>  $path
     */
    private function visitEdge(string $node, string $next, array &$color, array &$path): bool
    {
        if (!isset($this->stepsById[$next])) {
            $this->unresolvedReferences[$node][] = $next;

            return false;
        }

        if ($color[$next] === 1) {
            $this->recordCycle($next, $path);

            return true;
        }

        return $color[$next] === 0 && $this->visitNode($next, $color, $path);
    }

    /**
     * @param  list<string>  $path
     */
    private function recordCycle(string $target, array $path): void
    {
        $cycleIndex = array_search($target, $path, true);
        if ($cycleIndex !== false) {
            $this->cycle = array_slice($path, (int) $cycleIndex);
            $this->cycle[] = $target;
        }
    }

    /**
     * @return string[]
     */
    public function getTopologicalOrder(): array
    {
        return $this->topologicalOrder;
    }

    /**
     * @return string[]|null
     */
    public function getCycle(): ?array
    {
        return $this->cycle;
    }

    /**
     * @return array<string, string[]>
     */
    public function getUnresolvedReferences(): array
    {
        return $this->unresolvedReferences;
    }

    /**
     * Explicit dependsOn merged with implicit output-reference dependencies.
     *
     * @return list<string>
     */
    public function getEffectiveDependencies(string $stepId): array
    {
        return $this->effectiveDependencies[$stepId] ?? [];
    }

    /**
     * Transitive upstream dependencies of the given step in topological execution order,
     * resolving ties using workflow document declaration order.
     *
     * @return list<string>
     */
    public function getTransitiveDependencies(string $stepId): array
    {
        if (!isset($this->stepsById[$stepId])) {
            return [];
        }

        $ancestors = [];
        $queue = $this->effectiveDependencies[$stepId] ?? [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (!isset($ancestors[$current]) && isset($this->stepsById[$current])) {
                $ancestors[$current] = true;
                foreach ($this->effectiveDependencies[$current] ?? [] as $dep) {
                    $queue[] = $dep;
                }
            }
        }

        $result = [];
        foreach ($this->topologicalOrder as $id) {
            if (isset($ancestors[$id])) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * @return array<string, Step>
     */
    public function getStepsById(): array
    {
        return $this->stepsById;
    }
}
