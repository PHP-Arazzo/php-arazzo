<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Enum;

enum ParameterIn: string
{
    case Path = 'path';
    case Query = 'query';
    case Header = 'header';
    case Cookie = 'cookie';
    case Body = 'body';
    case Querystring = 'querystring';
    case Metadata = 'metadata';
    case Variable = 'variable';

    public function isSpec(): bool
    {
        return match ($this) {
            self::Path, self::Query, self::Header, self::Cookie => true,
            default => false,
        };
    }

    public function isExtension(): bool
    {
        return !$this->isSpec();
    }

    public static function tryFromRaw(string $value): ?self
    {
        $normalized = str_starts_with($value, 'x-') ? substr($value, 2) : $value;

        return self::tryFrom($normalized);
    }
}
