<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser\Decoders;

use Alama\Arazzo\Document\Parser\Decoders\YamlNodeRangeScanner;

it('finds node range for flow sequence with nested brackets', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = 'items: &list [1, 2, [3, 4]]';
    $anchorOffset = 7;
    $nodeStart = 12;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);

    expect(substr($text, $range['start'], $range['end'] - $range['start']))
        ->toBe('[1, 2, [3, 4]]');
});

it('finds node range for flow mapping with nested braces', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = 'obj: &map {a: 1, b: {c: 2}}';
    $anchorOffset = 5;
    $nodeStart = 9;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);

    expect(substr($text, $range['start'], $range['end'] - $range['start']))
        ->toBe('{a: 1, b: {c: 2}}');
});

it('finds node range for block mapping with nested indentation and blank lines', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = <<<'YAML'
item: &block
  child1: 1
  child2: 2

  child3: 3
sibling: 4
YAML;
    $anchorOffset = 6;
    $nodeStart = 12;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);
    $extracted = substr($text, $range['start'], $range['end'] - $range['start']);

    expect($extracted)->toContain('child1: 1')
        ->and($extracted)->toContain('child3: 3')
        ->and($extracted)->not->toContain('sibling: 4');
});

it('finds node range when anchor is at the end of text', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = 'empty: &anchor';
    $anchorOffset = 7;
    $nodeStart = 14;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);

    expect($range['start'])->toBe(14)
        ->and($range['end'])->toBe(14);
});

it('finds node range when anchor line has no subsequent newline', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = 'val: &anchor scalar_value';
    $anchorOffset = 5;
    $nodeStart = 12;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);

    expect($range['start'])->toBe(12)
        ->and($range['end'])->toBe(strlen($text));
});

it('skips whitespace and tabs before node start', function (): void {
    $scanner = new YamlNodeRangeScanner();
    $text = "val: &anchor \t [1, 2]";
    $anchorOffset = 5;
    $nodeStart = 12;

    $range = $scanner->findNodeRange($text, $anchorOffset, $nodeStart);

    expect(substr($text, $range['start'], $range['end'] - $range['start']))
        ->toBe('[1, 2]');
});
