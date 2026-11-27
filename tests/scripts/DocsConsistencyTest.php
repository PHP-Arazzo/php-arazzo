<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documentation consistency contract (#474)
|--------------------------------------------------------------------------
| Asserts that developer documentation and agent steering files stay aligned
| with quality gate requirements: explicit PHPMD design metrics in
| CODING-STANDARDS.md, and Windows PHP 8.4 setup and reactive wakeup
| patterns in AGENTS.md.
*/

function codingStandardsContents(string $repo): string
{
    $path = $repo.'/docs/CODING-STANDARDS.md';

    expect(file_exists($path))->toBeTrue('docs/CODING-STANDARDS.md must exist');

    return (string) file_get_contents($path);
}

function agentsDocContents(string $repo): string
{
    $path = $repo.'/AGENTS.md';

    expect(file_exists($path))->toBeTrue('AGENTS.md must exist at repository root');

    return (string) file_get_contents($path);
}

function gitattributesContents(string $repo): string
{
    $path = $repo.'/.gitattributes';

    expect(file_exists($path))->toBeTrue('.gitattributes must exist at repository root');

    return (string) file_get_contents($path);
}

function coverageInsightsDocContents(string $repo): string
{
    $path = $repo.'/.agents/skills/coverage-insights/SKILL.md';

    expect(file_exists($path))->toBeTrue('.agents/skills/coverage-insights/SKILL.md must exist');

    return (string) file_get_contents($path);
}

it('test_coding_standards_metrics_documented', function () {
    $repo = dirname(__DIR__, 2);
    $contents = codingStandardsContents($repo);

    expect($contents)
        ->toContain('CouplingBetweenObjects')
        ->toContain('13')
        ->toContain('ExcessiveClassComplexity')
        ->toContain('50')
        ->toContain('Single-Concern')
        ->toContain('@internal');
});

it('test_coding_standards_named_arguments_documented', function () {
    $repo = dirname(__DIR__, 2);
    $contents = codingStandardsContents($repo);

    expect($contents)
        ->toContain('named arguments')
        ->toContain('NormalizedOpenApiOperation');
});

it('test_agents_doc_windows_guidance', function () {
    $repo = dirname(__DIR__, 2);
    $contents = agentsDocContents($repo);

    expect($contents)
        ->toContain('8.4')
        ->toContain('Windows')
        ->toContain('PATH')
        ->toContain('docs/WINDOWS.md')
        ->toContain('reactive');

    $windowsDoc = $repo.'/docs/WINDOWS.md';
    expect(file_exists($windowsDoc))->toBeTrue('docs/WINDOWS.md must exist');
    expect((string) file_get_contents($windowsDoc))
        ->toContain('8.4')
        ->toContain('bootstrap-worktree.ps1');
});

it('test_gitattributes_lf_rules', function () {
    $repo = dirname(__DIR__, 2);
    $contents = gitattributesContents($repo);

    expect($contents)
        ->toContain('* text=auto eol=lf');
});

it('test_coverage_insights_driver_notes', function () {
    $repo = dirname(__DIR__, 2);
    $contents = coverageInsightsDocContents($repo);

    expect($contents)
        ->toContain('Xdebug')
        ->toContain('PCOV')
        ->toContain('ArchTest')
        ->toContain('PHPUnit 11');
});
