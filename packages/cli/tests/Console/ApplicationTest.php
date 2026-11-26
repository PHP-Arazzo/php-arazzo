<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Console;

use Alama\Arazzo\Cli\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

const CLI_PACKAGE_DIR = __DIR__.'/../../';

it('sets the application name and version', function (): void {
    $app = new Application();

    expect($app->getName())->toBe('arazzo')
        ->and($app->getVersion())->toBe('1.0.0-alpha');
});

it('registers the five arazzo console commands', function (): void {
    $app = new Application();

    $commands = array_keys($app->all());

    foreach (['validate', 'list-workflows', 'explain', 'run', 'render'] as $name) {
        expect($commands)->toContain($name);
    }
});

it('adds the working-dir global option', function (): void {
    $app = new Application();

    $def = $app->getDefinition();

    expect($def->hasOption('working-dir'))->toBeTrue()
        ->and($def->getOption('working-dir')->getShortcut())->toBe('d');
});

it('ships an arazzo binary declared in composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(CLI_PACKAGE_DIR.'composer.json'), true);

    $bin = $composer['bin'] ?? [];

    expect(is_array($bin))->toBeTrue()
        ->and(in_array('bin/arazzo', $bin, true))->toBeTrue()
        ->and(file_exists(CLI_PACKAGE_DIR.'bin/arazzo'))->toBeTrue();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(is_executable(CLI_PACKAGE_DIR.'bin/arazzo'))->toBeTrue();
    }
});

it('prints --version as one line and exits 0', function (): void {
    $app = new Application();
    $app->setAutoExit(false);

    $tester = new ApplicationTester($app);
    $tester->run(['--version' => true], ['interactive' => false]);

    $display = trim($tester->getDisplay());
    $lines = preg_split('/\R/', $display);

    expect(count($lines))->toBe(1)
        ->and($lines[0])->toContain('arazzo')
        ->and($tester->getStatusCode())->toBe(0);
});

it('executes bin/arazzo --version via CLI process successfully', function (): void {
    $binary = realpath(CLI_PACKAGE_DIR.'bin/arazzo');
    expect($binary)->not->toBeFalse();

    $process = new Process([PHP_BINARY, $binary, '--version'], CLI_PACKAGE_DIR);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('arazzo')
        ->and($process->getErrorOutput())->toBeEmpty();
});

it('executes bin/arazzo validate --help via CLI process successfully', function (): void {
    $binary = realpath(CLI_PACKAGE_DIR.'bin/arazzo');
    expect($binary)->not->toBeFalse();

    $process = new Process([PHP_BINARY, $binary, 'validate', '--help'], CLI_PACKAGE_DIR);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('validate')
        ->and($process->getErrorOutput())->toBeEmpty();
});

it('locates autoload.php when installed as a vendor dependency', function (): void {
    $tempDir = str_replace('\\', '/', sys_get_temp_dir()).'/arazzo-vendor-test-'.uniqid();
    $binDir = $tempDir.'/vendor/php-arazzo/arazzo-cli/bin';
    mkdir($binDir, 0777, true);

    copy(CLI_PACKAGE_DIR.'bin/arazzo', $binDir.'/arazzo');

    $monorepoAutoload = str_replace('\\', '/', (string) realpath(__DIR__.'/../../../../vendor/autoload.php'));
    file_put_contents($tempDir.'/vendor/autoload.php', "<?php require_once '{$monorepoAutoload}';\n");

    $process = new Process([PHP_BINARY, $binDir.'/arazzo', '--version']);
    $process->run();

    // Clean up
    @unlink($binDir.'/arazzo');
    @unlink($tempDir.'/vendor/autoload.php');
    @rmdir($binDir);
    @rmdir($tempDir.'/vendor/php-arazzo/arazzo-cli');
    @rmdir($tempDir.'/vendor/php-arazzo');
    @rmdir($tempDir.'/vendor');
    @rmdir($tempDir);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('arazzo')
        ->and($process->getErrorOutput())->toBeEmpty();
});

it('exits with status 1 and error message when no autoload.php can be found', function (): void {
    $tempDir = str_replace('\\', '/', sys_get_temp_dir()).'/arazzo-no-autoload-test-'.uniqid();
    $binDir = $tempDir.'/bin';
    mkdir($binDir, 0777, true);

    copy(CLI_PACKAGE_DIR.'bin/arazzo', $binDir.'/arazzo');

    $process = new Process([PHP_BINARY, $binDir.'/arazzo', '--version']);
    $process->run();

    // Clean up
    @unlink($binDir.'/arazzo');
    @rmdir($binDir);
    @rmdir($tempDir);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('You need to set up the project dependencies using Composer');
});
