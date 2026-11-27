param(
    [Parameter(Mandatory = $true)]
    [string]$SourceVendor
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $SourceVendor)) {
    Write-Error "Source vendor directory not found: $SourceVendor"
    exit 1
}

$phpDir = 'C:\ProgramData\envkit\services\php\8.4.27'
if (Test-Path $phpDir) {
    $env:PATH = "$phpDir;$env:PATH"
}

$worktreeRoot = (Get-Location).Path
$targetVendor = Join-Path $worktreeRoot 'vendor'

# Safely detach any existing vendor junction before copying.
if (Test-Path $targetVendor) {
    $item = Get-Item $targetVendor -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        cmd /c rmdir "$targetVendor"
    }
}

Write-Host "Copying vendor from $SourceVendor (excluding php-arazzo path links)..."
$excludedPhpArazzo = Join-Path $SourceVendor 'php-arazzo'
robocopy $SourceVendor $targetVendor /E /MT:8 /XD $excludedPhpArazzo /NFL /NDL /NJH /NJS /nc /ns /np | Out-Null
if ($LASTEXITCODE -gt 7) {
    Write-Error "robocopy failed with exit code $LASTEXITCODE"
    exit 1
}

$phpArazzoDir = Join-Path $targetVendor 'php-arazzo'
New-Item -ItemType Directory -Force -Path $phpArazzoDir | Out-Null

$packages = @(
    'cli',
    'contracts',
    'core',
    'document',
    'engine',
    'evaluation',
    'expression',
    'protocol-http',
    'protocol-rpc',
    'protocol-soap',
    'runner',
    'sources'
)

foreach ($pkg in $packages) {
    $linkPath = Join-Path $phpArazzoDir "arazzo-$pkg"
    $pkgTarget = Join-Path $worktreeRoot "packages\$pkg"

    if (Test-Path $linkPath) {
        $existing = Get-Item $linkPath -Force
        if ($existing.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            cmd /c rmdir "$linkPath"
        } else {
            Remove-Item -Recurse -Force $linkPath
        }
    }

    cmd /c mklink /J "$linkPath" "$pkgTarget" | Out-Null
}

Write-Host 'Regenerating Composer autoloader...'
composer dump-autoload
Write-Host 'Worktree vendor bootstrap complete.'
