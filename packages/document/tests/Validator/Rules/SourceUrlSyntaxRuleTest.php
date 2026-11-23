<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Plugins\ValidationRule\SourceUrlSyntaxRule;

it('flags empty and whitespace URLs, accepts good ones', function (): void {
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [
        new SourceDescription('a', '', SourceType::Openapi),
        new SourceDescription('b', 'bad url', SourceType::Openapi),
        new SourceDescription('c', 'ok.yaml', SourceType::Openapi),
    ], [], new Components([], [], [], []), []);
    $ec = new ErrorCollector();
    (new SourceUrlSyntaxRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(2);
});
