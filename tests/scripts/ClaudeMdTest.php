<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CLAUDE.md steering file contract (#455)
|--------------------------------------------------------------------------
| Pins the always-loaded agent steering document against the live monorepo:
| every current package under packages/ is mapped, stale 2-package and
| apptree-only claims are absent, verification/worktree workflows and
| high-signal pointers exist, and the file stays under 80 lines.
*/

/**
 * Read CLAUDE.md contents from the repository root.
 */
function claudeMdContents(string $repo): string
{
    $path = $repo.'/CLAUDE.md';

    expect(file_exists($path))->toBeTrue('CLAUDE.md must exist at repository root');

    return (string) file_get_contents($path);
}

it('lists current monorepo packages', function () {
    $repo = dirname(__DIR__, 2);
    $contents = claudeMdContents($repo);

    $packages = array_map(
        static fn (string $dir): string => basename($dir),
        glob($repo.'/packages/*', GLOB_ONLYDIR) ?: [],
    );

    expect($packages)->not->toBeEmpty();

    foreach ($packages as $package) {
        expect($contents)->toContain("packages/{$package}/");
    }

    expect($contents)
        ->not->toContain('packages/laravel')
        ->not->toContain('test-laravel');
});

it('documents verification and worktree workflows', function () {
    $repo = dirname(__DIR__, 2);
    $contents = claudeMdContents($repo);

    expect($contents)
        ->toContain('git worktree')
        ->toContain('make verify')
        ->toContain('composer run analyse')
        ->toContain('composer run check-baselines')
        ->not->toContain('use the apptree setup')
        ->not->toContain('scripts/apptree create');
});

it('points to adrs baselines and doc generator', function () {
    $repo = dirname(__DIR__, 2);
    $contents = claudeMdContents($repo);

    $pointers = [
        'docs/adr/' => $repo.'/docs/adr',
        'docs/CODING-STANDARDS.md' => $repo.'/docs/CODING-STANDARDS.md',
        'docs/BASELINE-POLICY.md' => $repo.'/docs/BASELINE-POLICY.md',
        'phpmd.baseline.xml' => $repo.'/phpmd.baseline.xml',
        'scripts/generate-docs.php' => $repo.'/scripts/generate-docs.php',
    ];

    foreach ($pointers as $needle => $targetPath) {
        expect($contents)->toContain($needle);
        expect(file_exists($targetPath))->toBeTrue("Referenced target missing: {$needle}");
    }

    expect($contents)->toContain('packages/*/phpstan-baseline.neon');
});

it('stays under 80 lines to protect always-loaded context budget', function () {
    $repo = dirname(__DIR__, 2);
    $contents = claudeMdContents($repo);
    $lineCount = substr_count($contents, "\n");

    expect($lineCount)->toBeLessThan(80);
});
