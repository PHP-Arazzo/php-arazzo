<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use Alama\Arazzo\Expression\Xpath\DomXpathEvaluator;
use Alama\Arazzo\Expression\Xpath\Xpath31Evaluator;
use Alama\Arazzo\Expression\Xpath\XpathEvaluator;

/**
 * XPath selector plugin. Delegates to a bound {@see XpathEvaluator}.
 *
 * The default {@see Xpath31Evaluator} (backed by {@see DomXpathEvaluator})
 * advertises its supported versions. A custom XpathEvaluator (e.g. Saxon/C for
 * XPath 3.1) can be bound to advertise additional versions.
 *
 * @internal stays out of the advertised contract; not part of the public API surface.
 */
#[AsSelectorPlugin(ExpressionType::XPath)]
final class XpathSelectorPlugin implements SelectorPluginInterface
{
    public function __construct(private XpathEvaluator $evaluator = new Xpath31Evaluator()) {}

    public function supports(ExpressionType $type): bool
    {
        return $type === ExpressionType::XPath;
    }

    /**
     * @param  mixed  $rootValue  XML string or \DOMNode
     */
    public function evaluate(mixed $rootValue, string $expression, ?string $version, ?array $namespaces = null): mixed
    {
        return $this->evaluator->query($rootValue, $expression, $version ?? 'xpath-10', $namespaces);
    }

    public function supportedVersions(): array
    {
        return $this->evaluator->supportedVersions();
    }
}
