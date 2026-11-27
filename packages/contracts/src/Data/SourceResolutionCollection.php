<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

use Countable;

final readonly class SourceResolutionCollection implements Countable
{
    /**
     * @param  array<string, SourceResolution>  $resolutions
     */
    public function __construct(
        private array $resolutions = [],
        private bool $resolved = false,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param  list<SourceResolution>|array<string, SourceResolution>  $resolutions
     */
    public static function fromArray(array $resolutions, bool $isResolved = true): self
    {
        $map = [];
        foreach ($resolutions as $res) {
            $map[$res->name] = $res;
        }

        return new self($map, $isResolved);
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function has(string $name): bool
    {
        return isset($this->resolutions[$name]);
    }

    public function get(string $name): ?SourceResolution
    {
        return $this->resolutions[$name] ?? null;
    }

    /**
     * @return array<string, SourceResolution>
     */
    public function all(): array
    {
        return $this->resolutions;
    }

    public function count(): int
    {
        return count($this->resolutions);
    }
}
