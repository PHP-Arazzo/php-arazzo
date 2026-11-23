<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Rpc\Normalizer;

use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoField;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoMethod;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoParser;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoService;
use InvalidArgumentException;

function calcProtoContent(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/calculator.proto') ?: '';
}

it('parses the package, services, methods, and messages of a .proto document', function (): void {
    $doc = (new ProtoParser())->parse(calcProtoContent());

    expect($doc->package)->toBe('demo.calculator');
    expect(array_map(static fn (ProtoService $s): string => $s->name, $doc->services))->toBe(['CalculatorService']);

    $service = $doc->services[0];
    expect(array_map(static fn (ProtoMethod $m): string => $m->name, $service->methods))->toBe(['Add', 'Divide', 'Ping']);

    $add = $service->methods[0];
    expect($add)->toBeInstanceOf(ProtoMethod::class);
    expect($add->requestType)->toBe('AddRequest');
    expect($add->responseType)->toBe('AddResponse');
    expect($add->requestStreaming)->toBeFalse();
    expect($add->responseStreaming)->toBeFalse();

    expect(array_keys($doc->messages))->toBe(['AddRequest', 'AddResponse', 'DivideRequest', 'DivideResponse']);

    $addRequest = $doc->messages['AddRequest'];
    expect(array_map(static fn (ProtoField $f): string => $f->name, $addRequest))->toBe(['a', 'b']);
    expect($addRequest[0]->type)->toBe('int32');
    expect($addRequest[0]->number)->toBe(1);
    expect($addRequest[1]->repeated)->toBeFalse();
});

it('tolerates comments, imports, options, and enums around the declarations', function (): void {
    $doc = (new ProtoParser())->parse(calcProtoContent());

    expect($doc->package)->toBe('demo.calculator');
    expect(count($doc->services))->toBe(1);
    expect(count($doc->messages))->toBe(4);
});

it('records streaming on rpc methods', function (): void {
    $proto = <<<'PROTO'
    syntax = "proto3";

    service Chat {
      rpc Talk (stream Request) returns (stream Response);
    }

    message Request { string text = 1; }
    message Response { string text = 1; }
    PROTO;

    $doc = (new ProtoParser())->parse($proto);

    expect($doc->services[0]->methods[0]->requestStreaming)->toBeTrue();
    expect($doc->services[0]->methods[0]->responseStreaming)->toBeTrue();
});

it('throws when the content is not a proto3 document', function (): void {
    (new ProtoParser())->parse('this is not a proto file at all');
})->throws(InvalidArgumentException::class, 'proto3');

it('throws for a proto2 syntax header', function (): void {
    (new ProtoParser())->parse("syntax = \"proto2\";\npackage stale;\nservice Old { rpc Run (In) returns (Out); }\nmessage In { string x = 1; }\nmessage Out { string y = 1; }");
})->throws(InvalidArgumentException::class, 'proto3');

it('handles comments, repeated fields, field options, nested blocks, service options, and unknown declarations', function (): void {
    $proto = <<<'PROTO'
    /* leading block comment */
    syntax = 'proto3';
    ;
    package demo.v1;
    import "google/protobuf/empty.proto";
    option (custom) = { nested: "v" / 2 };
    enum Kind { KIND_UNSPECIFIED = 0; }
    unknown_decl foo = 1;
    extend { body { inner } }
    service Echo {
      ;
      option (svc_opt) = true;
      rpc Send (demo.v1.Req) returns (demo.v1.Resp) { option (opt) = { a: 1 }; };
    }
    message Req {
      ;
      repeated string tags = 1 [deprecated = true];
      map<string, int32> labels = 2;
      reserved 10;
      message Nested { string inner = 1; { nested } }
      oneof choice { string a = 3; }
    }
    // trailing comment without newline
    PROTO;

    $doc = (new ProtoParser())->parse($proto);

    expect($doc->package)->toBe('demo.v1')
        ->and($doc->services)->toHaveCount(1)
        ->and($doc->services[0]->methods[0]->name)->toBe('Send')
        ->and($doc->messages['Req'])->toHaveCount(1)
        ->and($doc->messages['Req'][0]->name)->toBe('tags')
        ->and($doc->messages['Req'][0]->repeated)->toBeTrue();
});

it('rejects malformed tokens for expected word, symbol, string, and field number', function (): void {
    $parser = new ProtoParser();

    expect(fn () => $parser->parse('123'))->toThrow(InvalidArgumentException::class, 'Expected syntax')
        ->and(fn () => $parser->parse('syntax "proto3";'))->toThrow(InvalidArgumentException::class, "Expected '='")
        ->and(fn () => $parser->parse('syntax = 123;'))->toThrow(InvalidArgumentException::class, 'Expected a string literal')
        ->and(fn () => $parser->parse('syntax = "proto3"; message M { string x = nope; }'))->toThrow(InvalidArgumentException::class, 'Expected a field number');
});
