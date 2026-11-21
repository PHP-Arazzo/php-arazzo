<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Document\Parser\Decoders\NativeJsonDecoder;
use Alama\Arazzo\Document\Parser\Decoders\SymfonyYamlDecoder;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;

final readonly class Decoders
{
    public static function for(Format $format, ?DecodePolicy $policy = null): DecoderInterface
    {
        return match ($format) {
            Format::Json => new NativeJsonDecoder($policy),
            Format::Yaml => new SymfonyYamlDecoder($policy),
        };
    }
}
