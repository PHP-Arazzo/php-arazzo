# Agent Guidelines & Developer Environment

Guidance for autonomous AI agents and human contributors developing in the `php-arazzo` monorepo.

## 1. Windows PHP 8.4+ Environment Setup

The monorepo strictly requires PHP 8.4+ (`>= 8.4.1` platform check).

- **Binary Discovery:** On Windows environments equipped with `envkit`, the active PHP 8.4 binary is located at:
  ```powershell
  C:\ProgramData\envkit\services\php\8.4.27\php.exe
  ```
- **PATH Configuration:** Prepend PHP 8.4 to your active PowerShell session before executing PHP tools:
  ```powershell
  $env:PATH = "C:\ProgramData\envkit\services\php\8.4.27;$env:PATH"
  ```
- **Verification:** Run `php -v` and `composer --version` to ensure PHP 8.4 is the active CLI interpreter.
- **PowerShell Command Chaining:** In Windows PowerShell 5.1, the Bash-style `&&` operator is invalid. Chain dependent commands with `; if ($?) { ... }` or separate independent statements with `;`.

## 2. Reactive Asynchronous Execution Patterns

- **No Busy-Waiting / Polling Loops:** When invoking long-running commands, tests, or subagents asynchronously, never execute manual polling loops or terminal sleep timers.
- **Reactive Wakeup:** Yield the turn immediately after backgrounding a task. The execution environment reactively wakes and delivers high-priority completion events when background jobs finish.

## 3. Verification & Quality Gates

Run quality verification commands via Composer (cross-platform):

```bash
composer run test             # Run all Pest test suites
composer run phpmd            # Run PHPMD static analysis across all 12 packages
composer run analyse          # Run PHPStan across all 12 packages
composer run check-baselines  # Verify baseline policy compliance (no silent suppressions)
composer run format           # Format codebase with Laravel Pint
```

On hosts with GNU Make available, `make verify` executes the full pre-push gate.

## 4. Architectural Pointers & Standards

- **Coding Standards:** Follow [`docs/CODING-STANDARDS.md`](docs/CODING-STANDARDS.md) for domain exception factories, tell-don't-ask DTO queries, and explicit design metric ceilings (`CouplingBetweenObjects` ≤ 13, `ExcessiveClassComplexity` ≤ 50, `TooManyPublicMethods` ≤ 10).
- **Architecture Decision Records:** Consult [`docs/adr/`](docs/adr/) for system-wide contracts, plugin attributes, and observability policies.
- **Baseline Policy:** Governed by [`docs/BASELINE-POLICY.md`](docs/BASELINE-POLICY.md).
