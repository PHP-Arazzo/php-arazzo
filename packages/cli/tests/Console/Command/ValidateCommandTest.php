<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Tests\Console\Command;

use Alama\Arazzo\Cli\Console\Command\ValidateCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function createDocWithExtensions(string $dir): string
{
    $path = $dir.'/extensions.arazzo.yaml';
    $content = <<<'YAML'
arazzo: "1.1.0"
info:
  title: Extensions Repro
  version: "1.0"
sourceDescriptions:
  - name: api
    url: http://localhost:8002/docs/api.json
    type: openapi
workflows:
  - workflowId: testWf
    steps:
      - stepId: step1
        operationId: api.doSomething
        parameters:
          - name: payload
            in: x-body
            value: data
        onSuccess:
          - name: invokeSub
            type: x-invoke
            workflowId: testWf
YAML;
    file_put_contents($path, $content);

    return $path;
}

function createDocWithDuplicateReplacements(string $dir): string
{
    $path = $dir.'/duplicate-replacements.arazzo.yaml';
    $content = <<<'YAML'
arazzo: "1.0.0"
info:
  title: Duplicate Replacements Repro
  version: "1.0"
sourceDescriptions:
  - name: api
    url: http://localhost:8002/docs/api.json
    type: openapi
workflows:
  - workflowId: testWf
    steps:
      - stepId: step1
        operationId: api.doSomething
        requestBody:
          contentType: application/json
          replacements:
            - target: /prop
              value: "first"
            - target: /prop
              value: "second"
YAML;
    file_put_contents($path, $content);

    return $path;
}

function createDocWithUnknownField(string $dir): string
{
    $path = $dir.'/unknown-field.arazzo.yaml';
    $content = <<<'YAML'
arazzo: "1.0.0"
info:
  title: Unknown Field Repro
  version: "1.0"
unknownField: "surprise"
sourceDescriptions:
  - name: api
    url: http://localhost:8002/docs/api.json
    type: openapi
workflows:
  - workflowId: testWf
    steps:
      - stepId: step1
        operationId: api.doSomething
YAML;
    file_put_contents($path, $content);

    return $path;
}

it('fails on duplicate replacements by default with exit code 1', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithDuplicateReplacements($tempDir);

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute(['file' => $file]);

        expect($status)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('step.request_body_replacements_duplicate_target');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('demotes severity via --severity CLI flag and exits 0 when only warnings remain', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithDuplicateReplacements($tempDir);

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--severity' => ['step.request_body_replacements_duplicate_target:warn'],
        ]);

        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('✔ valid')
            ->and($tester->getDisplay())->toContain('Warnings:')
            ->and($tester->getDisplay())->toContain('step.request_body_replacements_duplicate_target');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('disables rule via --disable-rule CLI flag', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithDuplicateReplacements($tempDir);

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--disable-rule' => ['step.request_body_replacements_duplicate_target'],
        ]);

        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('✔ valid')
            ->and($tester->getDisplay())->not->toContain('step.request_body_replacements_duplicate_target');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('toggles strict mode via --no-strict flag', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithUnknownField($tempDir);

    try {
        // By default strict is true -> doc.unknown_field is an Error -> FAILURE
        $strictTester = new CommandTester(new ValidateCommand());
        $strictStatus = $strictTester->execute(['file' => $file]);
        expect($strictStatus)->toBe(Command::FAILURE)
            ->and($strictTester->getDisplay())->toContain('doc.unknown_field');

        // With --no-strict -> doc.unknown_field is a Warning -> SUCCESS
        $nonStrictTester = new CommandTester(new ValidateCommand());
        $nonStrictStatus = $nonStrictTester->execute([
            'file' => $file,
            '--no-strict' => true,
        ]);
        expect($nonStrictStatus)->toBe(Command::SUCCESS)
            ->and($nonStrictTester->getDisplay())->toContain('✔ valid')
            ->and($nonStrictTester->getDisplay())->toContain('doc.unknown_field');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('loads policy from config file and allows CLI flags to override', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithDuplicateReplacements($tempDir);
    $configFile = $tempDir.'/custom-validate.json';

    // Config disables the rule
    file_put_contents($configFile, json_encode([
        'validator' => [
            'disabled' => ['step.request_body_replacements_duplicate_target'],
        ],
    ]));

    try {
        // 1. With config file alone, rule is disabled -> SUCCESS
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--config' => $configFile,
        ]);
        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->not->toContain('step.request_body_replacements_duplicate_target');

        // 2. Overriding config with --enable-rule re-enables the rule -> FAILURE
        $overrideTester = new CommandTester(new ValidateCommand());
        $overrideStatus = $overrideTester->execute([
            'file' => $file,
            '--config' => $configFile,
            '--enable-rule' => ['step.request_body_replacements_duplicate_target'],
        ]);
        expect($overrideStatus)->toBe(Command::FAILURE)
            ->and($overrideTester->getDisplay())->toContain('step.request_body_replacements_duplicate_target');
    } finally {
        unlink($configFile);
        unlink($file);
        rmdir($tempDir);
    }
});

it('rejects invalid --severity format with exit code 2 (Command::INVALID)', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithDuplicateReplacements($tempDir);

    try {
        $tester = new CommandTester(new ValidateCommand());

        // Missing level
        $statusMissing = $tester->execute([
            'file' => $file,
            '--severity' => ['some-rule'],
        ]);
        expect($statusMissing)->toBe(Command::INVALID)
            ->and($tester->getDisplay())->toContain("Invalid severity option 'some-rule'");

        // Invalid level
        $statusBogus = $tester->execute([
            'file' => $file,
            '--severity' => ['some-rule:bogus'],
        ]);
        expect($statusBogus)->toBe(Command::INVALID)
            ->and($tester->getDisplay())->toContain("Invalid severity option 'some-rule:bogus'");
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('passes documents with beyond-spec extensions in default permissive mode but rejects them with --conformance', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_conf_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithExtensions($tempDir);

    try {
        // 1. Default (permissive) -> SUCCESS
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute(['file' => $file]);
        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('✔ valid');

        // 2. Conformance mode -> FAILURE
        $confTester = new CommandTester(new ValidateCommand());
        $confStatus = $confTester->execute([
            'file' => $file,
            '--conformance' => true,
        ]);
        expect($confStatus)->toBe(Command::FAILURE)
            ->and($confTester->getDisplay())->toContain('✘ invalid')
            ->and($confTester->getDisplay())->toContain('step.parameter_in_valid')
            ->and($confTester->getDisplay())->toContain('action.type_valid');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

function createDocWithSourcesTest(string $dir, string $sourceUrl, ?string $sourceType = 'openapi'): string
{
    $typeLine = $sourceType !== null ? "    type: {$sourceType}" : '';
    $path = $dir.'/doc.arazzo.yaml';
    $content = <<<YAML
arazzo: "1.0.0"
info:
  title: Source Resolution Test
  version: "1.0"
sourceDescriptions:
  - name: api
    url: {$sourceUrl}
{$typeLine}
workflows:
  - workflowId: testWf
    steps:
      - stepId: step1
        operationId: api.doSomething
YAML;
    file_put_contents($path, $content);

    return $path;
}

it('fetches and validates every source in resolve mode when --resolve-sources is supplied', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_resolve_'.uniqid();
    mkdir($tempDir);
    $openapiFile = $tempDir.'/openapi.yaml';
    file_put_contents($openapiFile, "openapi: 3.0.0\ninfo:\n  title: Petstore\n  version: 1.0.0\npaths:\n  /pets:\n    get:\n      operationId: doSomething\n");
    $file = createDocWithSourcesTest($tempDir, './openapi.yaml', 'openapi');

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--resolve-sources' => true,
        ]);

        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('✔ valid')
            ->and($tester->getDisplay())->not->toContain('source.');
    } finally {
        unlink($openapiFile);
        unlink($file);
        rmdir($tempDir);
    }
});

it('reports unreadable source with source.unreadable in resolve mode', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_resolve_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithSourcesTest($tempDir, './missing.yaml', 'openapi');

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--resolve-sources' => true,
        ]);

        expect($status)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('✘ invalid')
            ->and($tester->getDisplay())->toContain('source.unreadable')
            ->and($tester->getDisplay())->toContain('/sourceDescriptions/0/url');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});

it('reports unparseable source with source.unparseable in resolve mode', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_resolve_'.uniqid();
    mkdir($tempDir);
    $badFile = $tempDir.'/bad.yaml';
    file_put_contents($badFile, "invalid: [unclosed yaml\n");
    $file = createDocWithSourcesTest($tempDir, './bad.yaml', 'openapi');

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--resolve-sources' => true,
        ]);

        expect($status)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('✘ invalid')
            ->and($tester->getDisplay())->toContain('source.unparseable')
            ->and($tester->getDisplay())->toContain('/sourceDescriptions/0/url');
    } finally {
        unlink($badFile);
        unlink($file);
        rmdir($tempDir);
    }
});

it('reports declared type mismatch with source.type_matches in resolve mode', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_resolve_'.uniqid();
    mkdir($tempDir);
    $openapiFile = $tempDir.'/openapi.yaml';
    file_put_contents($openapiFile, "openapi: 3.0.0\ninfo:\n  title: Petstore\n  version: 1.0.0\npaths:\n  /pets:\n    get:\n      operationId: doSomething\n");
    // Points to openapi.yaml, but declared type is arazzo
    $file = createDocWithSourcesTest($tempDir, './openapi.yaml', 'arazzo');

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
            '--resolve-sources' => true,
        ]);

        expect($status)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('✘ invalid')
            ->and($tester->getDisplay())->toContain('source.type_matches')
            ->and($tester->getDisplay())->toContain('/sourceDescriptions/0/type');
    } finally {
        unlink($openapiFile);
        unlink($file);
        rmdir($tempDir);
    }
});

it('stays offline without --resolve-sources even when source file is missing', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_val_offline_'.uniqid();
    mkdir($tempDir);
    $file = createDocWithSourcesTest($tempDir, './missing.yaml', 'openapi');

    try {
        $tester = new CommandTester(new ValidateCommand());
        $status = $tester->execute([
            'file' => $file,
        ]);

        expect($status)->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('✔ valid')
            ->and($tester->getDisplay())->not->toContain('source.unreadable');
    } finally {
        unlink($file);
        rmdir($tempDir);
    }
});
