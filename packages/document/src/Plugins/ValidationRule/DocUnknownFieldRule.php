<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class DocUnknownFieldRule implements ValidationRuleInterface
{
    private const array KNOWN = ['arazzo', 'info', 'sourceDescriptions', 'workflows', 'components', '$self'];

    public function __construct(public readonly bool $strict = true) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if ($doc->rawRoot === null) {
            return;
        }
        foreach ($doc->rawRoot as $rawKey => $_) {
            // A decoded root map may hold integer keys (`{"0": …}`); fold them to
            // strings so a numeric top-level field is reported instead of skipped.
            $k = (string) $rawKey;
            if (in_array($k, self::KNOWN, true) || str_starts_with($k, 'x-')) {
                continue;
            }
            $msg = "Unknown top-level field '{$k}'.";
            $path = '/'.str_replace(['~', '/'], ['~0', '~1'], $k);
            if ($this->strict) {
                $errors->error($this->code(), $msg, $path);
            } else {
                $errors->warning($this->code(), $msg, $path);
            }
        }
    }

    public function code(): string
    {
        return 'doc.unknown_field';
    }
}
