<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Enum;

use Alama\Arazzo\Contracts\Exceptions\InvalidSpecVersionException;

enum SpecVersion: string
{
    case V1_0 = '1.0.0';
    case V1_1 = '1.1.0';
    case V1_2 = '1.2.0';

    public static function fromRaw(string $raw): self
    {
        if (preg_match('/^1\.0\.\d+$/', $raw) === 1) {
            return self::V1_0;
        }

        if (preg_match('/^1\.1\.\d+$/', $raw) === 1) {
            return self::V1_1;
        }

        if (preg_match('/^1\.2\.\d+$/', $raw) === 1) {
            return self::V1_2;
        }

        throw InvalidSpecVersionException::unsupported($raw);
    }
}
