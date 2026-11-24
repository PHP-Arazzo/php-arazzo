<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Renderer;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;

/**
 * Renders Arazzo documents as Mermaid flowcharts and Markdown docs.
 * Pure presentation: no parsing, no execution.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class Renderer
{
    public function toMermaid(ArazzoDocument $document, ?string $workflowId = null): string
    {
        return (new MermaidRenderer())->render($document, $workflowId);
    }

    public function toMarkdown(ArazzoDocument $document): string
    {
        return (new MarkdownRenderer())->render($document);
    }
}
