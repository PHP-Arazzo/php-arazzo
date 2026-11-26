<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Parser;
use Alama\Arazzo\Expression\Support\ExpressionWalker;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ExpressionContextMisuseRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly ParserInterface $parser = new Parser(),
        private readonly ExpressionWalker $walker = new ExpressionWalker(),
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($this->walker->walk($doc, $symbols) as $site) {
            $ref = $this->parser->projectReferences($site->expression->raw);
            if ($ref === null) {
                continue;
            }

            if ($ref->isRuntimeHttpReference() && !$site->allowsRuntimeHttpReference()) {
                $errors->error(
                    $this->code(),
                    "Runtime reference (\$response/\$request/\$statusCode/\$url/\$method) is not valid in context '{$site->context->value}'.",
                    $site->pointer,
                );
            }
        }
    }

    public function code(): string
    {
        return 'expr.context_misuse';
    }
}
