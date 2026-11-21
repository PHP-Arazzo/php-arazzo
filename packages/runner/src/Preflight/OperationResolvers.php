<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Document\ResolvedOperation;
use RuntimeException;

final readonly class OperationResolvers
{
    /**
     * @param  iterable<OperationResolverInterface>  $resolvers
     */
    public function __construct(
        private iterable $resolvers,
    ) {}

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supportsStep($step, $document)) {
                return $resolver->resolve($step, $document);
            }
        }

        throw new RuntimeException("No operation resolver supports step '{$step->stepId}'.");
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supportsStep($step, $document)) {
                return true;
            }
        }

        return false;
    }
}
