<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Expression\Data\EmbeddedSegment;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Exceptions\SelectorEvaluationException;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Expression\Policy\InterpolationPolicy;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;

/**
 * Concrete expression facade: static parsing and reference projection, plus
 * runtime evaluation of expressions and selectors.
 *
 * PHPMD counts twelve public methods (TooManyPublicMethods). That is the
 * facade's intended surface — parse, extract, interpolate, test, evaluate,
 * selectors, JSONPath, JSON Pointer and XPath — and splitting it would make
 * every caller depend on several objects instead of one entry point. Kept as a
 * single facade, not waived for convenience (#260).
 */
final readonly class ExpressionEngine implements ExpressionEngineInterface
{
    private ExpressionDependencyProvider $provider;

    public function __construct()
    {
        $this->provider = new ExpressionDependencyProvider();
    }

    public static function create(): self
    {
        return new self();
    }

    public function parseExpression(string $raw): ?ExpressionSyntaxException
    {
        $result = $this->provider->getParser()->parseOrError($raw);

        return $result instanceof ExpressionSyntaxException ? $result : null;
    }

    public function expressionReferences(string $raw): ?ExpressionReference
    {
        $result = $this->provider->getParser()->parseOrError($raw);

        return $result instanceof ExpressionSyntaxException ? null : $this->provider->getParser()->projectReferences($raw);
    }

    public function extract(string $template): array
    {
        return $this->getInterpolator()->extract($template);
    }

    /**
     * @return list<EmbeddedSegment>
     */
    public function parseEmbedded(string $raw, SpecVersion $version = SpecVersion::V1_0): array
    {
        return $this->provider->getEmbeddedExpressionSplitter()->split($raw, $version);
    }

    public function interpolate(string $template, callable $resolver, array $options = []): string
    {
        return $this->getInterpolator()->interpolate($template, $resolver, $options);
    }

    public function interpolateWithPolicy(string $template, callable $resolver, ?InterpolationPolicy $policy = null): string
    {
        return $this->getInterpolator()->interpolateWithPolicy($template, $resolver, $policy);
    }

    public function test(string $raw): bool
    {
        return $this->getInterpolator()->test($raw);
    }

    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed
    {
        return $this->provider->getExpressionEvaluator()->evaluate($expression, $context);
    }

    public function evaluateSelector(Selector $selector, WorkflowContextInterface $context, string $stepId): mixed
    {
        return $this->provider->getSelectorEvaluator()->evaluate($selector, $context, $stepId);
    }

    public function jsonPath(string $expression, array|object $data): mixed
    {
        return $this->provider->getJsonPathEvaluator()->evaluate($expression, $data);
    }

    public function jsonPointer(array $data, ?string $pointer): mixed
    {
        return JsonPointer::resolve($data, $pointer);
    }

    public function evaluateRawSelector(
        ExpressionType $type,
        string $expression,
        mixed $data,
        ?string $version = null,
        ?array $namespaces = null,
    ): mixed {
        $plugin = SelectorPluginRegistry::create()->get($type);

        if ($plugin === null) {
            throw new SelectorEvaluationException(
                "No selector plugin registered for type '{$type->value}'.",
                '',
                'selector.no_plugin',
            );
        }

        return $plugin->evaluate($data, $expression, $version, $namespaces);
    }

    public function xpath(
        string $expression,
        mixed $data,
        ?string $version = null,
        ?array $namespaces = null,
    ): mixed {
        return $this->evaluateRawSelector(ExpressionType::XPath, $expression, $data, $version, $namespaces);
    }

    public function supportedSelectorVersions(ExpressionType $type): array
    {
        return SelectorPluginRegistry::create()->supportedVersions($type);
    }

    private function getInterpolator(): Interpolator
    {
        return $this->provider->getInterpolator();
    }
}
