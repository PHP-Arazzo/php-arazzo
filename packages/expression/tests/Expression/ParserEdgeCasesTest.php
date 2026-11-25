<?php

declare(strict_types=1);

use Alama\Arazzo\Expression\Ast\ResponsePart;
use Alama\Arazzo\Expression\Ast\StepRef;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\JsonPath\Parser as JsonPathParser;
use Alama\Arazzo\Expression\JsonPath\TokenCursor;
use Alama\Arazzo\Expression\JsonPath\Tokenizer;
use Alama\Arazzo\Expression\Parser;

it('covers runtime expression parser edge cases and error paths', function (): void {
    $parser = new Parser();

    // projectReferences returns null on syntax error
    expect($parser->projectReferences('$unknown.foo'))->toBeNull();

    // Bare $steps.s.response (empty http part tail)
    $bare = $parser->parse('$steps.s.response');
    expect($bare)->toBeInstanceOf(StepRef::class)
        ->and($bare->part)->toBeInstanceOf(ResponsePart::class);

    // Malformed array index pointer segments
    expect(fn () => $parser->parse('$steps.s.outputs.items[0]foo'))->toThrow(ExpressionSyntaxException::class, "Expected '['");
    expect(fn () => $parser->parse('$steps.s.outputs.items[]'))->toThrow(ExpressionSyntaxException::class, 'Expected array index');
    expect(fn () => $parser->parse('$steps.s.outputs.items[0'))->toThrow(ExpressionSyntaxException::class, "Expected ']'");
    expect(fn () => $parser->parse('$steps.s.outputs.items[0].'))->toThrow(ExpressionSyntaxException::class, "Expected name after '.'");

    // Malformed workflow/source/http/inputs tails
    expect(fn () => $parser->parse('$workflows.wf.other.x'))->toThrow(ExpressionSyntaxException::class, 'inputs or outputs');
    expect(fn () => $parser->parse('$sourceDescriptions.api#foo'))->toThrow(ExpressionSyntaxException::class, "Expected '.' after source name");
    expect(fn () => $parser->parse('$steps.s.response#foo'))->toThrow(ExpressionSyntaxException::class, "Expected '.' after part");
    expect(fn () => $parser->parse('$steps.s.response.statusCode.extra'))->toThrow(ExpressionSyntaxException::class, 'Unexpected tokens');
    expect(fn () => $parser->parse('$steps.s.inputs.id.extra'))->toThrow(ExpressionSyntaxException::class, 'Malformed reference');
});

it('covers JSONPath parser and cursor edge cases', function (): void {
    // Bracket string child selector
    $ast = (new JsonPathParser((new Tokenizer())->tokenize("\$['book']")))->parse();
    expect($ast['steps'][0])->toBe(['kind' => 'child', 'name' => 'book']);

    // WORD token with '@' value inside filter
    $wordCurrentAst = (new JsonPathParser([
        ['ROOT', '$'],
        ['LBRACKET', '['],
        ['OPERATOR', '?'],
        ['WORD', '@'],
        ['RBRACKET', ']'],
    ]))->parse();
    expect($wordCurrentAst['steps'][0]['predicate'])->toBe(['kind' => 'current']);

    // Unexpected non-word primary in filter expression
    expect(fn () => (new JsonPathParser((new Tokenizer())->tokenize('$[?]]')))->parse())
        ->toThrow(RuntimeException::class, 'Unexpected token in filter expression');

    // TokenCursor::expect value mismatch
    $cursor = new TokenCursor([['ROOT', '!']]);
    expect(fn () => $cursor->expect('ROOT', '$'))
        ->toThrow(RuntimeException::class, "Expected token ROOT '$'");
});
