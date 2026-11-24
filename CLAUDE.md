# php-arazzo

PHP monorepo for alama/arazzo-core + alama/laravel-arazzo.

## Working on features

When working on any feature or GitHub issue, use the apptree setup:

1. Create isolated environment: `scripts/apptree create <branch-name>`
2. Work inside the container: `scripts/apptree shell <branch-name>`
3. Run tests: `scripts/apptree test <branch-name>`
4. When done: `scripts/apptree down <branch-name>`

## Available commands

- `scripts/apptree create <branch>` - Create worktree + start Docker
- `scripts/apptree shell <branch>` - Open shell in container
- `scripts/apptree test <branch>` - Run Pest tests
- `scripts/apptree down <branch>` - Stop containers + remove worktree
- `scripts/apptree list` - List active instances

## Testing

```bash
# Run all tests
composer run test

# Run core tests only
composer run test-core

# Run laravel tests only
composer run test-laravel

# Run with coverage
make test-coverage
```

## Code quality

```bash
# Format code
composer run format

# Static analysis
composer run analyse

# Full verification
make verify
```

Suppressions are governed by [`docs/BASELINE-POLICY.md`](docs/BASELINE-POLICY.md) — a
baseline may freeze style findings, never correctness ones, and `composer run
check-baselines` enforces that.

## Coding Standards (Mandatory for Contributors & Agents)

All code changes MUST follow [`docs/CODING-STANDARDS.md`](docs/CODING-STANDARDS.md):

- **Domain exceptions with static factories**: never throw bare `RuntimeException` / `InvalidArgumentException` / `LogicException` with inline message strings; use package domain exceptions with named static factories (e.g. `GotoTargetNotFoundException::unknownStep($stepId, $workflowId)`).
- **Rich DTOs (Tell, Don't Ask)**: place pure queries and predicates over DTO data on the DTO itself (`$document->findWorkflow()`, `$document->findSource()`, `$workflow->findStep()`, `$step->isActorHandoff()`, `$context->getStepOutputs()`) instead of repeating lookup helpers across callers.
- **Symmetric decomposition & zero baseline regressions**: extract sibling collaborators symmetrically, mark internal helpers `@internal`, and never add PHPMD/PHPStan baseline entries.

## Project structure

- `packages/core/` - Arazzo core library
- `packages/laravel/` - Laravel integration package
- `tests/` - Test suites
- `scripts/` - Development scripts
