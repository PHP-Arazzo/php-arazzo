<?php

declare(strict_types=1);

/**
 * Ensures:
 * - cli imports only the allow-listed public API of runner (Runner, RunnerInterface,
 *   AsyncRunner, AsyncRunnerInterface, Data\*, Queue\SyncQueueDriver, Registries\*,
 *   Events\*, Exceptions\*) and never @internal runner classes
 * - core does not require alama/arazzo-cli (breaks shared-services→adapter circular dep)
 * - core/engine are framework-agnostic (no illuminate/* imports)
 */
function cliSrcFiles(string $repo): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repo.'/packages/cli/src', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('cli imports only public runner API and no runner internals', function (): void {
    $repo = __DIR__.'/../..';
    $cliFiles = cliSrcFiles($repo);

    $allowedRunnerPrefixes = [
        'Alama\\Arazzo\\Runner\\Runner',
        'Alama\\Arazzo\\Runner\\RunnerInterface',
        'Alama\\Arazzo\\Runner\\AsyncRunner',
        'Alama\\Arazzo\\Runner\\AsyncRunnerInterface',
        'Alama\\Arazzo\\Runner\\ExecutionStoreInterface',
        'Alama\\Arazzo\\Runner\\DefinitionStoreInterface',
        'Alama\\Arazzo\\Runner\\Data\\',
        'Alama\\Arazzo\\Runner\\Policy\\',
        'Alama\\Arazzo\\Runner\\Store\\',
        'Alama\\Arazzo\\Runner\\Queue\\SyncQueueDriver',
        'Alama\\Arazzo\\Runner\\Registries\\OperationExecutorRegistry',
        'Alama\\Arazzo\\Runner\\Events\\',
        'Alama\\Arazzo\\Runner\\Exceptions\\',
    ];

    $hasFacade = false;
    foreach ($cliFiles as $file) {
        $content = (string) file_get_contents($file);
        if (str_contains($content, 'use Alama\\Arazzo\\Runner\\Runner;') || str_contains($content, 'use Alama\\Arazzo\\Runner\\AsyncRunner;')) {
            $hasFacade = true;
        }

        preg_match_all('/^use\s+(Alama\\\\Arazzo\\\\Runner\\\\[^;]+);/m', $content, $matches);
        foreach ($matches[1] as $imported) {
            $allowed = false;
            foreach ($allowedRunnerPrefixes as $prefix) {
                if ($imported === $prefix || str_starts_with($imported, $prefix)) {
                    $allowed = true;
                    break;
                }
            }
            expect($allowed)->toBeTrue("cli imports non-public runner symbol {$imported} in {$file}");
        }
    }

    expect($hasFacade)->toBeTrue('cli should import Runner or AsyncRunner');
});

it('core does not require alama/arazzo-cli', function (): void {
    $repo = __DIR__.'/../..';
    $coreManifest = json_decode((string) file_get_contents($repo.'/packages/core/composer.json'), true);
    $require = $coreManifest['require'] ?? [];

    expect(array_key_exists('alama/arazzo-cli', $require))
        ->toBeFalse('core requires alama/arazzo-cli (circular dep)');
    expect(array_key_exists('php-arazzo/arazzo-cli', $require))
        ->toBeFalse('core requires php-arazzo/arazzo-cli (circular dep)');
});

it('core and engine are framework-agnostic (no illuminate imports)', function (): void {
    $repo = __DIR__.'/../..';
    $coreSrc = $repo.'/packages/core/src';
    $engineSrc = $repo.'/packages/engine/src';

    foreach ([$coreSrc, $engineSrc] as $dir) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            expect(str_contains($content, 'Illuminate\\'))
                ->toBeFalse('framework-agnostic package '.$file.' imports Illuminate\\');
        }
    }
});
