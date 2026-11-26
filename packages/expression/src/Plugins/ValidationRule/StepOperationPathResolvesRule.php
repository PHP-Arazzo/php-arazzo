<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Internal\EmbeddedExpressionSplitter;
use Alama\Arazzo\Expression\Parser;

/**
 * Resolves an operationPath against the operations declared in its
 * referenced source description.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepOperationPathResolvesRule implements ValidationRuleInterface
{
    private readonly ParserInterface $parser;

    private readonly EmbeddedExpressionSplitter $splitter;

    public function __construct(
        ?ParserInterface $parser = null,
        ?EmbeddedExpressionSplitter $splitter = null,
    ) {
        $this->parser = $parser ?? new Parser();
        $this->splitter = $splitter ?? new EmbeddedExpressionSplitter($this->parser);
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                $path = "/workflows/{$wi}/steps/{$si}/operationPath";
                $this->checkStep($s, $path, $doc->specVersion, $symbols, $errors);
            }
        }
    }

    private function checkStep(
        Step $s,
        string $path,
        SpecVersion $version,
        SymbolTable $symbols,
        ErrorCollector $errors,
    ): void {
        $operationPath = $s->target->operationPath;
        if ($operationPath === null || !str_contains($operationPath, '#')) {
            // Missing or syntax error handled by StepOperationPathSyntaxRule.
            return;
        }

        [$src, $ptr] = explode('#', $operationPath, 2);
        $sourceName = $this->sourceName($src, $version);

        if ($sourceName === null) {
            // Malformed source expression handled by StepOperationPathSyntaxRule.
            return;
        }

        if (!isset($symbols->sourceDescriptions[$sourceName])) {
            $errors->error(
                $this->code(),
                "Step '{$s->stepId}' uses unknown source qualifier '{$sourceName}' in operationPath '{$operationPath}'.",
                $path,
            );

            return;
        }

        if (!$symbols->knownOperations->covers($sourceName)) {
            // No resolved operations available (unreachable/skipped
            // source per ADR-005): skip the existence check.
            return;
        }

        if (!$this->isOperationKnown($symbols, $sourceName, $ptr)) {
            $errors->error(
                $this->code(),
                "Step '{$s->stepId}' uses unresolved operationPath '{$operationPath}'.",
                $path,
            );
        }
    }

    private function isOperationKnown(SymbolTable $symbols, string $sourceName, string $ptr): bool
    {
        $pointerWithHash = '#'.$ptr;
        $normalizedPtr = $this->lowercaseMethod($ptr);
        $normalizedWithHash = '#'.$normalizedPtr;

        return $symbols->knownOperations->has($sourceName, $ptr)
            || $symbols->knownOperations->has($sourceName, $pointerWithHash)
            || $symbols->knownOperations->has($sourceName, $normalizedPtr)
            || $symbols->knownOperations->has($sourceName, $normalizedWithHash);
    }

    private function sourceName(string $source, SpecVersion $version): ?string
    {
        try {
            $segments = $this->splitter->split($source, $version);
        } catch (ExpressionSyntaxException) {
            return null;
        }

        if (count($segments) !== 1) {
            return null;
        }

        $segment = $segments[0];
        if (!$segment->isSoleExpressionOf($source)) {
            return null;
        }

        $reference = $this->parser->projectReferences($segment->text);

        return $reference !== null && $reference->isSourceUrl()
            ? $reference->target
            : null;
    }

    private function lowercaseMethod(string $ptr): string
    {
        $lastSlash = strrpos($ptr, '/');
        if ($lastSlash === false) {
            return $ptr;
        }

        return substr($ptr, 0, $lastSlash + 1).strtolower(substr($ptr, $lastSlash + 1));
    }

    public function code(): string
    {
        return 'step.operationpath_resolves';
    }
}
