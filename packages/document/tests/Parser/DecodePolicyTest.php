<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\YamlPolicy;

it('exposes bounded fields with secure defaults on DecodePolicy and YamlPolicy', function (): void {
    $policy = DecodePolicy::default();

    expect($policy->maxBytes)->toBe(5_242_880)
        ->and($policy->maxDepth)->toBe(64)
        ->and($policy->maxNodes)->toBe(100_000)
        ->and($policy->yaml)->toBeInstanceOf(YamlPolicy::class)
        ->and($policy->yaml->maxAliases)->toBe(100)
        ->and($policy->yaml->allowCustomTags)->toBeFalse()
        ->and($policy->yaml->allowObjectDeserialization)->toBeFalse();
});

it('supports immutable updates via withers', function (): void {
    $policy = DecodePolicy::default()
        ->withMaxBytes(1024)
        ->withMaxDepth(10)
        ->withMaxNodes(500)
        ->withYamlPolicy(
            YamlPolicy::default()
                ->withMaxAliases(5)
                ->withAllowCustomTags(true)
                ->withAllowObjectDeserialization(true),
        );

    expect($policy->maxBytes)->toBe(1024)
        ->and($policy->maxDepth)->toBe(10)
        ->and($policy->maxNodes)->toBe(500)
        ->and($policy->yaml->maxAliases)->toBe(5)
        ->and($policy->yaml->allowCustomTags)->toBeTrue()
        ->and($policy->yaml->allowObjectDeserialization)->toBeTrue();
});
