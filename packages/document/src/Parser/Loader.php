<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Contracts\Spec\RawDocument;
use Alama\Arazzo\Document\Parser\Decoders\NativeJsonDecoder;
use Alama\Arazzo\Document\Parser\Decoders\SymfonyYamlDecoder;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class Loader
{
    private readonly DecoderRegistry $decoders;

    private function __construct(DecoderRegistry $decoders)
    {
        $this->decoders = $decoders;
    }

    /**
     * Create a Loader with decoders configured via DecodePolicy.
     */
    public static function defineDecodePolicy(?DecodePolicy $policy = null): self
    {
        $policy ??= DecodePolicy::default();

        return new self(
            DecoderRegistry::fromArray([
                Format::Yaml->value => new SymfonyYamlDecoder($policy),
                Format::Json->value => new NativeJsonDecoder($policy),
            ]),
        );
    }

    /**
     * @deprecated Use Loader::defineDecodePolicy() instead.
     */
    public static function new(): self
    {
        return self::defineDecodePolicy();
    }

    /**
     * Create a Loader with custom decoders.
     * Useful for testing or adding new formats without modifying this class.
     *
     * @param  array<string, DecoderInterface>  $decoders  Keys are format values (e.g. 'yaml', 'json')
     */
    public static function withDecoders(array $decoders): self
    {
        return new self(DecoderRegistry::fromArray($decoders));
    }

    public function load(string $path): RawDocument
    {
        if (!is_file($path)) {
            throw LoaderException::notFound($path);
        }
        if (!is_readable($path)) {
            throw LoaderException::notReadable($path);
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $format = Format::fromExtension($ext)
            ?? throw LoaderException::unsupportedExtension($ext);

        $decoder = $this->decoders->all()[$format->value] ?? null;
        if ($decoder === null) {
            throw LoaderException::unsupportedFormat($format);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw LoaderException::readFailed($path);
        }

        return $this->decodeRaw($raw, $path, $format, $decoder);
    }

    public function loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): RawDocument
    {
        $decoder = $this->decoders->all()[Format::Yaml->value] ?? null;
        if ($decoder === null) {
            throw LoaderException::unsupportedFormat(Format::Yaml);
        }

        return $this->decodeRaw($content, $virtualPath, Format::Yaml, $decoder);
    }

    public function loadFromJson(string $content, string $virtualPath = 'inline.json'): RawDocument
    {
        $decoder = $this->decoders->all()[Format::Json->value] ?? null;
        if ($decoder === null) {
            throw LoaderException::unsupportedFormat(Format::Json);
        }

        return $this->decodeRaw($content, $virtualPath, Format::Json, $decoder);
    }

    private function decodeRaw(string $raw, string $path, Format $format, DecoderInterface $decoder): RawDocument
    {
        try {
            $data = $decoder->decode($raw);
        } catch (DecodeException $e) {
            throw LoaderException::decodeFailed($path, $e);
        }

        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw LoaderException::rootNotObject($path);
        }

        /** @var array<string,mixed> $data */
        return new RawDocument($data, $path, $format);
    }
}
