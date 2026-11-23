<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Interfaces;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * SPI contract for Arazzo document validation rules.
 *
 * Rules can live in `arazzo-document` (structural/semantic core rules),
 * `arazzo-expression` (runtime expression & selector rules), protocol packages
 * (WSDL/RPC/etc.), or user applications, and are auto-discovered via
 * `#[AsValidationRulePlugin]` + `composer.json` `extra.arazzo.plugins`.
 */
interface ValidationRuleInterface
{
    public function code(): string;

    public function check(
        ArazzoDocument $doc,
        SymbolTable $symbols,
        ErrorCollector $errors,
    ): void;
}
