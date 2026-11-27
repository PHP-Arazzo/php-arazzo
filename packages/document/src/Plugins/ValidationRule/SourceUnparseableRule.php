<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Validates that fetched source document content is parseable as a valid YAML/JSON object.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class SourceUnparseableRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'source.unparseable';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if (!$symbols->sourceResolutions->isResolved()) {
            return;
        }

        foreach ($doc->sourceDescriptions as $i => $source) {
            $res = $symbols->sourceResolutions->get($source->name);
            if ($res !== null && $res->isUnparseable()) {
                $reason = $res->errorMessage ?? 'Unknown parse error';
                $errors->error(
                    $this->code(),
                    "Source '{$source->name}' at '{$source->url}' could not be parsed: {$reason}",
                    "/sourceDescriptions/{$i}/url",
                );
            }
        }
    }
}
