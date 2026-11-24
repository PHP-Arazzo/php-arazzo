<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution;

use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Runner\Exceptions\InvalidSourcePolicyException;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;

/**
 * Validates source-addressed {@see SourcePolicy} entries against an Arazzo
 * document fail-fast before step 1 and resolves the single policy belonging
 * to an active step so credentials and server overrides never leak across APIs.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SourcePolicyResolver
{
    public function validate(ArazzoDocument $document, ExecutionPolicy $policy): void
    {
        if ($policy->sourcePolicies === []) {
            return;
        }

        /** @var array<string, string> $seenUrls */
        $seenUrls = [];

        foreach (array_keys($policy->sourcePolicies) as $key) {
            if (str_contains($key, '.')) {
                throw InvalidSourcePolicyException::dottedKeyReserved($key);
            }

            $source = $document->findSource($key);
            if ($source === null) {
                throw InvalidSourcePolicyException::unknownSource($key);
            }

            if ($source->isArazzo()) {
                throw InvalidSourcePolicyException::arazzoSourceNotAddressable($key);
            }

            if (isset($seenUrls[$source->url])) {
                throw InvalidSourcePolicyException::duplicateSourceUrl($seenUrls[$source->url], $key, $source->url);
            }
            $seenUrls[$source->url] = $key;
        }
    }

    public function resolveForStep(Step $step, ArazzoDocument $document, ExecutionPolicy $policy): ?SourcePolicy
    {
        if ($policy->sourcePolicies === [] || $step->target->workflowId !== null) {
            return null;
        }

        $sourceName = $this->sourceNameForStep($step, $document);

        return $sourceName !== null ? $policy->sourcePolicy($sourceName) : null;
    }

    private function sourceNameForStep(Step $step, ArazzoDocument $document): ?string
    {
        $apiSources = array_map(
            static fn (SourceDescription $source): string => $source->name,
            $document->nonArazzoSources(),
        );

        if ($step->target->operationPath !== null && preg_match('/^\$sourceDescriptions\.([^.#\/]+)\./', $step->target->operationPath, $matches) === 1) {
            return in_array($matches[1], $apiSources, true) ? $matches[1] : null;
        }

        foreach ([$step->target->operationId, $step->target->operationName, $step->target->rpcMethod, $step->target->channelPath] as $identifier) {
            if ($identifier === null || !str_contains($identifier, '.')) {
                continue;
            }
            $prefix = substr($identifier, 0, (int) strpos($identifier, '.'));
            if (in_array($prefix, $apiSources, true)) {
                return $prefix;
            }
        }

        return count($apiSources) === 1 ? $apiSources[0] : null;
    }
}
