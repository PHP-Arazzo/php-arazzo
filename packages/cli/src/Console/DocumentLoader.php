<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/** Shared document loading for console commands.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class DocumentLoader
{
    /**
     * @throws LoaderException
     * @throws ParserException
     */
    public static function load(string $file): ArazzoDocument
    {
        return Document::create()->load($file);
    }
}
