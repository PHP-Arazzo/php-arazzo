<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use Alama\Arazzo\Contracts\Spec\SourceIdentity;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable collection of SourceIdentity entries providing O(1) identity-first then location-second lookups.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 *
 * @implements IteratorAggregate<int, SourceIdentity>
 */
final readonly class SourceIdentityCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<string, SourceIdentity>  $bySelf
     * @param  array<string, SourceIdentity>  $byRetrieval
     * @param  list<SourceIdentity>  $all
     */
    public function __construct(
        private array $bySelf = [],
        private array $byRetrieval = [],
        private array $all = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param  array<SourceIdentity>  $identities
     */
    public static function fromArray(array $identities): self
    {
        $collection = new self();
        foreach ($identities as $identity) {
            $collection = $collection->with($identity);
        }

        return $collection;
    }

    /**
     * Immutably add a SourceIdentity.
     *
     * - If selfUri is present and already registered:
     *   - Same retrievalUri: idempotent no-op.
     *   - Different retrievalUri: throws duplicateSelfUri exception.
     *
     * @throws SourceResolutionException
     */
    public function with(SourceIdentity $identity): self
    {
        $selfUri = $identity->selfUri;
        $retrievalUri = $identity->retrievalUri;

        if ($selfUri !== null && $selfUri !== '') {
            if (isset($this->bySelf[$selfUri])) {
                $existing = $this->bySelf[$selfUri];
                if ($existing->retrievalUri === $retrievalUri) {
                    return $this;
                }

                throw SourceResolutionException::duplicateSelfUri(
                    selfUri: $selfUri,
                    existingRetrievalUri: $existing->retrievalUri,
                    newRetrievalUri: $retrievalUri,
                );
            }
        }

        $bySelf = $this->bySelf;
        if ($selfUri !== null && $selfUri !== '') {
            $bySelf[$selfUri] = $identity;
        }

        $byRetrieval = $this->byRetrieval;
        $byRetrieval[$retrievalUri] = $identity;

        $all = $this->all;
        $all[] = $identity;

        return new self($bySelf, $byRetrieval, $all);
    }

    public function findBySelf(string $uri): ?SourceIdentity
    {
        return $this->bySelf[$uri] ?? null;
    }

    public function findByRetrieval(string $uri): ?SourceIdentity
    {
        return $this->byRetrieval[$uri] ?? null;
    }

    /**
     * Resolves by Identity URI ($self) first, then falls back to Retrieval URI (Arazzo 1.1.0 §5.2).
     */
    public function find(string $uri): ?SourceIdentity
    {
        return $this->bySelf[$uri] ?? $this->byRetrieval[$uri] ?? null;
    }

    public function hasSelf(string $uri): bool
    {
        return isset($this->bySelf[$uri]);
    }

    public function hasRetrieval(string $uri): bool
    {
        return isset($this->byRetrieval[$uri]);
    }

    public function has(string $uri): bool
    {
        return isset($this->bySelf[$uri]) || isset($this->byRetrieval[$uri]);
    }

    /**
     * @return list<SourceIdentity>
     */
    public function all(): array
    {
        return $this->all;
    }

    public function count(): int
    {
        return count($this->all);
    }

    public function isEmpty(): bool
    {
        return $this->all === [];
    }

    /**
     * @return Traversable<int, SourceIdentity>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all);
    }
}
