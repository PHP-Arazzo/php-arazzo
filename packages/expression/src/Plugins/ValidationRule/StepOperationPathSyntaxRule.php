<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Internal\EmbeddedExpressionSplitter;
use Alama\Arazzo\Expression\Parser;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepOperationPathSyntaxRule implements ValidationRuleInterface
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
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                if ($s->target->operationPath === null) {
                    continue;
                }
                $path = "/workflows/{$i}/steps/{$j}/operationPath";
                if (!str_contains($s->target->operationPath, '#')) {
                    $errors->error($this->code(), "operationPath '{$s->target->operationPath}' must contain '#' separating source and JSON Pointer.", $path);

                    continue;
                }
                [$src, $ptr] = explode('#', $s->target->operationPath, 2);
                $sourceName = $this->sourceName($src, $doc->specVersion);

                if ($sourceName === null) {
                    $errors->error($this->code(), "operationPath source '{$src}' must be the expression '{{\$sourceDescriptions.NAME.url}}'.", $path);

                    continue;
                }

                if (!isset($symbols->sourceDescriptions[$sourceName])) {
                    $errors->error($this->code(), "operationPath source '{$sourceName}' is not a declared sourceDescription.", $path);
                }
                if ($ptr === '' || $ptr[0] !== '/') {
                    $errors->error($this->code(), "operationPath JSON Pointer '{$ptr}' must start with '/'.", $path);
                }
            }
        }
    }

    private function sourceName(string $source, SpecVersion $version): ?string
    {
        try {
            $segments = $this->splitter->split($source, $version);
        } catch (ExpressionSyntaxException) {
            return null;
        }

        // The source part must be exactly one embedded expression, not a
        // template that merely contains one.
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

    public function code(): string
    {
        return 'step.operationpath_syntax';
    }
}
