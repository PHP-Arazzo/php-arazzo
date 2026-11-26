<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Tests\Console\Config;

use Alama\Arazzo\Cli\Console\Config\ValidateConfigLoader;
use Alama\Arazzo\Contracts\Validation\Severity;

it('returns default ValidatorPolicy when no configuration file exists', function (): void {
    $loader = new ValidateConfigLoader();
    $tempDir = sys_get_temp_dir().'/arazzo_cfg_none_'.uniqid();
    mkdir($tempDir);

    try {
        $policy = $loader->load(null, $tempDir);

        expect($policy->severities)->toBe([])
            ->and($policy->disabled)->toBe([])
            ->and($policy->isStrict())->toBeTrue();
    } finally {
        rmdir($tempDir);
    }
});

it('loads arazzo.validate.php returning a ValidatorPolicy instance', function (): void {
    $loader = new ValidateConfigLoader();
    $tempDir = sys_get_temp_dir().'/arazzo_cfg_php_obj_'.uniqid();
    mkdir($tempDir);
    $filePath = $tempDir.'/arazzo.validate.php';

    file_put_contents(
        $filePath,
        "<?php return Alama\Arazzo\Contracts\Validation\ValidatorPolicy::default()"
        ."->withSeverity('rule.one', Alama\Arazzo\Contracts\Validation\Severity::Warning)"
        ."->withDisabled('rule.two')"
        .'->withStrict(false);',
    );

    try {
        $policy = $loader->load(null, $tempDir);

        expect($policy->severityFor('rule.one'))->toBe(Severity::Warning)
            ->and($policy->isDisabled('rule.two'))->toBeTrue()
            ->and($policy->isStrict())->toBeFalse();
    } finally {
        unlink($filePath);
        rmdir($tempDir);
    }
});

it('loads arazzo.validate.php returning an array', function (): void {
    $loader = new ValidateConfigLoader();
    $tempDir = sys_get_temp_dir().'/arazzo_cfg_php_arr_'.uniqid();
    mkdir($tempDir);
    $filePath = $tempDir.'/arazzo.validate.php';

    file_put_contents(
        $filePath,
        "<?php return [
            'severity' => ['rule.one' => 'warn', 'rule.two' => 'error'],
            'disabled' => ['rule.three'],
            'strict' => false,
        ];",
    );

    try {
        $policy = $loader->load(null, $tempDir);

        expect($policy->severityFor('rule.one'))->toBe(Severity::Warning)
            ->and($policy->severityFor('rule.two'))->toBe(Severity::Error)
            ->and($policy->isDisabled('rule.three'))->toBeTrue()
            ->and($policy->isStrict())->toBeFalse();
    } finally {
        unlink($filePath);
        rmdir($tempDir);
    }
});

it('loads arazzo.json with validator section', function (): void {
    $loader = new ValidateConfigLoader();
    $tempDir = sys_get_temp_dir().'/arazzo_cfg_json_'.uniqid();
    mkdir($tempDir);
    $filePath = $tempDir.'/arazzo.json';

    file_put_contents(
        $filePath,
        json_encode([
            'validator' => [
                'severity' => [
                    'requestBody-replacements-unique' => 'warning',
                ],
                'disabled' => ['expr.forward_step_ref'],
                'strict' => false,
            ],
        ]),
    );

    try {
        $policy = $loader->load(null, $tempDir);

        expect($policy->severityFor('requestBody-replacements-unique'))->toBe(Severity::Warning)
            ->and($policy->isDisabled('expr.forward_step_ref'))->toBeTrue()
            ->and($policy->isStrict())->toBeFalse();
    } finally {
        unlink($filePath);
        rmdir($tempDir);
    }
});

it('loads from explicit config path', function (): void {
    $loader = new ValidateConfigLoader();
    $tempDir = sys_get_temp_dir().'/arazzo_cfg_exp_'.uniqid();
    mkdir($tempDir);
    $filePath = $tempDir.'/custom-rules.json';

    file_put_contents(
        $filePath,
        json_encode([
            'validator' => [
                'severity' => ['custom.rule' => 'warn'],
            ],
        ]),
    );

    try {
        $policy = $loader->load($filePath);

        expect($policy->severityFor('custom.rule'))->toBe(Severity::Warning);
    } finally {
        unlink($filePath);
        rmdir($tempDir);
    }
});

it('loads conformance option from config array', function (): void {
    $loader = new ValidateConfigLoader();
    $policy = $loader->buildFromArray(['conformance' => true]);
    expect($policy->isConformance())->toBeTrue();
});

it('throws InvalidArgumentException when explicit config path does not exist', function (): void {
    $loader = new ValidateConfigLoader();
    $loader->load('/non/existent/path/arazzo.json');
})->throws(\InvalidArgumentException::class, 'Configuration file "/non/existent/path/arazzo.json" not found.');
