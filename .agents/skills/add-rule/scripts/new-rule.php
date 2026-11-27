<?php

declare(strict_types=1);

// Scaffolds an auto-discovered #[AsValidationRulePlugin] rule + Pest test in packages/document.
//
// Usage:
//   php new-rule.php <name> <dotted.code> [--dry-run]
//
// Examples:
//   php new-rule.php "WorkflowTimeout" "workflow.timeout"
//   php new-rule.php "step-max-retries" "step.max_retries"

$fail = static function (string $msg): never {
    fwrite(STDERR, "error: {$msg}\n");
    exit(2);
};

$root = dirname(__DIR__, 4);
$dryRun = in_array('--dry-run', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--dry-run'));

if (count($args) !== 2) {
    fwrite(STDERR, "usage: php new-rule.php <name> <dotted.code> [--dry-run]\n");
    exit(2);
}

[$rawName, $code] = $args;

// Normalise the class name: kebab/snake/camel input -> PascalCase + mandatory Rule suffix.
$words = preg_split('/[^A-Za-z0-9]+/', $rawName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$class = implode('', array_map(ucfirst(...), $words));
$class = preg_replace('/Rule$/', '', $class).'Rule';

if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $class)) {
    $fail("class name '{$class}' is not a valid PHP class name");
}

if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $code)) {
    $fail("code '{$code}' must be dotted lower_snake segments, e.g. step.outputs_unique");
}

$rulePath = "{$root}/packages/document/src/Plugins/ValidationRule/{$class}.php";
$testPath = "{$root}/packages/document/tests/Validator/Rules/{$class}Test.php";

foreach ([$rulePath, $testPath] as $p) {
    if (file_exists($p)) {
        $fail(basename($p).' already exists');
    }
}

$ruleTemplate = <<<'PHP'
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class {{CLASS}} implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        // TODO: implement the validation.
    }

    public function code(): string
    {
        return '{{CODE}}';
    }
}

PHP;

$testTemplate = <<<'PHP'
<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Plugins\ValidationRule\{{CLASS}};

it('is a no-op on a minimal document', function (): void {
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $ec = new ErrorCollector();
    $r = new {{CLASS}}();
    $r->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toBe([])
        ->and($ec->warnings())->toBe([])
        ->and($r->code())->toBe('{{CODE}}');
});

PHP;

$render = static fn (string $t): string => str_replace(['{{CLASS}}', '{{CODE}}'], [$class, $code], $t);

echo "rule:     {$class}\n";
echo "code:     {$code}\n";
echo "target:   packages/document/src/Plugins/ValidationRule/{$class}.php\n";

if ($dryRun) {
    echo "dry run — no files written\n";
    exit(0);
}

file_put_contents($rulePath, $render($ruleTemplate));
file_put_contents($testPath, $render($testTemplate));

echo "written:  packages/document/src/Plugins/ValidationRule/{$class}.php\n";
echo "written:  packages/document/tests/Validator/Rules/{$class}Test.php\n";
echo "\nnext:\n";
echo "  vendor/bin/pint packages/document/src/Plugins/ValidationRule/{$class}.php packages/document/tests/Validator/Rules/{$class}Test.php\n";
echo "  vendor/bin/pest packages/document/tests/Validator/Rules/{$class}Test.php\n";
echo "  implement check(), then: composer run analyse-document && composer run phpmd\n";
