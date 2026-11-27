# Agent Guidelines

Guidance for autonomous AI agents and contributors developing in the `php-arazzo` monorepo (PHP 8.4+).

## 1. Verification & Quality Gates

```bash
composer run test             # Run all Pest suites (or composer run test-<pkg>, see composer.json)
composer run analyse          # Run PHPStan across all 12 packages (or composer run analyse-<pkg>)
composer run phpmd            # Run PHPMD across all 12 packages
composer run check-baselines  # Verify baseline policy compliance (no silent suppressions)
composer run format           # Format codebase with Laravel Pint
composer run docs             # Regenerate docs/generated/ architecture diagrams and API docs
```

- On hosts with GNU Make available, `make verify` runs the full pre-push gate.
- Enable pre-commit hooks with `git config core.hooksPath .githooks`.
- Yield immediately after backgrounding long-running commands and wait for reactive completion events.
- **Targeted Feedback Loops:** When developing or debugging, run the targeted package or file test suite first (e.g. `vendor/bin/pest packages/runner/tests/Sync/StepExecutorFailureHandlingTest.php`) before invoking full-monorepo test runs.

## 2. Context Pointers

- **Coding Standards & Design Ceilings:** [`docs/CODING-STANDARDS.md`](docs/CODING-STANDARDS.md) (`CBO ≤ 13`, `WMC ≤ 50`, `cyclomaticComplexity ≤ 10`, `identifierLength` 3–30, `maxParameterCount ≤ 5`, domain exceptions, concrete DI over speculative interfaces).
- **Architecture Decision Records:** [`docs/adr/`](docs/adr/) for facade contracts, plugin attributes, and observability policies.
- **Baseline Policy:** [`docs/BASELINE-POLICY.md`](docs/BASELINE-POLICY.md).
- **Domain Glossary:** [`CONTEXT.md`](CONTEXT.md).
- **Windows / PowerShell Setup:** [`docs/WINDOWS.md`](docs/WINDOWS.md) for PHP 8.4 `PATH` setup, PowerShell 5.1 chaining, and worktree `vendor/` bootstrap (`scripts/bootstrap-worktree.ps1`).
