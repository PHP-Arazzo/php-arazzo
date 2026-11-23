<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Interfaces;

use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
interface ParserInterface
{
    public function parse(string $raw): ExpressionAst;

    public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException;

    public function projectReferences(string $raw): ?ExpressionReference;
}
