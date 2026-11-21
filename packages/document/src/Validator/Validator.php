<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\KnownOperations;
use Alama\Arazzo\Document\Validator\Data\SymbolTable;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Validator
{
    /**
     * @param  KnownOperations|array<string,array<string,true>>|null  $resolvedOperations
     */
    public function __construct(
        private readonly RuleSet $rules,
        private readonly KnownOperations|array|null $resolvedOperations = null,
    ) {}

    /**
     * @param  KnownOperations|array<string,array<string,true>>|null  $resolvedOperations
     */
    public function validate(ArazzoDocument $doc, KnownOperations|array|null $resolvedOperations = null): ValidationResult
    {
        $raw = $resolvedOperations ?? $this->resolvedOperations;
        $ops = match (true) {
            $raw instanceof KnownOperations => $raw,
            is_array($raw) => new KnownOperations($raw),
            default => KnownOperations::none(),
        };

        $symbols = SymbolTable::build($doc, $ops);
        $collector = new ErrorCollector();

        foreach ($this->rules->activeRules() as $rule) {
            $rule->check($doc, $symbols, $collector);
        }

        return new ValidationResult($doc, $collector->errors(), $collector->warnings());
    }
}
