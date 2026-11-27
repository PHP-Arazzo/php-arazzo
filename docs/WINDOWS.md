# Windows & PowerShell Development Guide

Windows-specific setup and worktree notes for developing in the `php-arazzo` monorepo.

## 1. PHP 8.4+ PATH Setup

The monorepo requires PHP 8.4+ (`>= 8.4.1`). On Windows hosts with `envkit`, prepend PHP 8.4 to `PATH` before running PHP or Composer commands:

```powershell
$env:PATH = "C:\ProgramData\envkit\services\php\8.4.27;$env:PATH"
```

Verify with `php -v` and `composer --version`.

## 2. PowerShell 5.1 Command Chaining

Bash-style `&&` is invalid in Windows PowerShell 5.1. Chain dependent commands with `; if ($?) { ... }` and separate independent commands with `;`.

## 3. Git Worktree `vendor/` Bootstrap

Fresh git worktrees on Windows need their own local `vendor/` directory rather than a junction to another worktree's `vendor/`.

### Known Gotchas

1. **Never junction `vendor/` itself.** Pest computes `$rootPath = dirname(realpath($autoloadPath), 2)`. When `vendor/` points to another worktree, Pest fails to strip the prefix and throws `InvalidTestClassName` on numeric-prefixed worktree directories (e.g. `371-...`).
2. **Never delete a junction with `Remove-Item -Recurse`.** Windows PowerShell 5.1 follows directory junctions and deletes the target folder's files. Always remove junctions with `cmd /c rmdir <path>`.
3. **`vendor/php-arazzo/arazzo-*` path junctions are required.** `PluginDiscovery` (`Composer\InstalledVersions`) and `Alama\Arazzo\Evaluation\` rely on `vendor/php-arazzo/arazzo-*` pointing to this worktree's `packages/*`.
4. **`phpdocumentor/shim` GPG key errors.** If `composer install` fails in `phar-io/composer-distributor` (`Could not import required GPG key!`), bootstrap `vendor/` from a healthy worktree using `scripts/bootstrap-worktree.ps1`.

### Bootstrap Script

From the root of the new worktree:

```powershell
$env:PATH = "C:\ProgramData\envkit\services\php\8.4.27;$env:PATH"
powershell -ExecutionPolicy Bypass -File scripts/bootstrap-worktree.ps1 -SourceVendor "E:\PHP-Arazzo\php-arazzo\vendor"
```

## 4. Git Pre-Commit Hooks

Enable hooks with `git config core.hooksPath .githooks` and ensure Git Bash discovers PHP 8.4 on `PATH` so `php scripts/generate-docs.php` runs cleanly.
