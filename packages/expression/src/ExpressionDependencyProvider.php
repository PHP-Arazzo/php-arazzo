<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression;

use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Expression\Internal\EmbeddedExpressionSplitter;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;
use Alama\Arazzo\Expression\Xpath\DomXpathEvaluator;
use Alama\Arazzo\Expression\Xpath\XpathEvaluator;

/**
 * @internal Package-internal dependency factory. Not for external use.
 * Used by ExpressionEngine to construct and cache internal collaborators.
 */
final class ExpressionDependencyProvider
{
    private ?XpathEvaluator $xpathEvaluator = null;

    private ?EmbeddedExpressionSplitter $embeddedExpressionSplitter = null;

    private ?ExpressionEvaluator $expressionEvaluator = null;

    private ?SelectorEvaluator $selectorEvaluator = null;

    public function __construct() {}

    public function getExpressionEngine(): ExpressionEngineInterface
    {
        return new ExpressionEngine();
    }

    public function getExpressionEvaluator(): ExpressionEvaluator
    {
        // Held per provider (and therefore per engine) so the bounded AST cache
        // survives across evaluations instead of being rebuilt on every call.
        return $this->expressionEvaluator ??= new ExpressionEvaluator($this->getJsonPointer());
    }

    public function getSelectorEvaluator(): SelectorEvaluator
    {
        return $this->selectorEvaluator ??= new SelectorEvaluator($this->getSelectorPluginRegistry(), $this->getExpressionEvaluator());
    }

    public function getJsonPathEvaluator(): JsonPathEvaluator
    {
        return new JsonPathEvaluator();
    }

    public function getXpathEvaluator(): XpathEvaluator
    {
        return $this->xpathEvaluator ??= new DomXpathEvaluator();
    }

    public function getSelectorPluginRegistry(): SelectorPluginRegistry
    {
        return SelectorPluginRegistry::create();
    }

    public function getParser(): Parser
    {
        return new Parser();
    }

    public function getJsonPointer(): JsonPointer
    {
        return new JsonPointer();
    }

    public function getInterpolator(): Interpolator
    {
        return new Interpolator();
    }

    public function getEmbeddedExpressionSplitter(): EmbeddedExpressionSplitter
    {
        return $this->embeddedExpressionSplitter ??= new EmbeddedExpressionSplitter($this->getParser());
    }
}
