# php-arazzo

Framework-agnostic PHP 8.4+ monorepo implementing the Arazzo 1.0.0/1.1.0 Specification (`php-arazzo/arazzo-*`; Laravel integration lives in `php-arazzo/laravel-arazzo`).

## Worktree Workflow

Use native git worktrees with local PHP 8.4+ (or Herd); `scripts/apptree` is optional for Docker-isolated runs:

```bash
git worktree add .worktrees/<branch> -b <branch>
cd .worktrees/<branch> && composer install
```

## Verification & Quality Gates

```bash
make verify                   # Pre-push gate: docs, pint --test, analyse, check-baselines, pest
composer run test             # Run all Pest suites (or composer run test-<pkg>)
composer run analyse          # Run PHPStan across all 12 packages
composer run check-baselines  # Enforce docs/BASELINE-POLICY.md (no correctness suppressions)
composer run format           # Format with Laravel Pint
make phpmd                    # Run PHPMD against phpmd.xml + phpmd.baseline.xml
```

## Context Pointers

- **Writing or refactoring PHP:** follow [`docs/CODING-STANDARDS.md`](docs/CODING-STANDARDS.md) (domain exceptions with static factories, rich DTOs, symmetric decomposition, `@internal` collaborators).
- **Changing public seams, plugins, or observability:** consult [`docs/adr/`](docs/adr/) — facade seams (`ADR-006`, `ADR-007`, `ADR-009`), `As<Name>Plugin` + `src/Plugins/<Name>/` convention (`ADR-008`), observability tiers & `*Policy` VOs (`ADR-010`).
- **Moving or splitting classes:** update [`phpmd.baseline.xml`](phpmd.baseline.xml) (`make phpmd-baseline`) and `packages/*/phpstan-baseline.neon` (`make analyse-baseline`), governed by [`docs/BASELINE-POLICY.md`](docs/BASELINE-POLICY.md).
- **Changing namespaces, packages, or public APIs:** run [`scripts/generate-docs.php`](scripts/generate-docs.php) (`composer run docs`) to refresh `docs/generated/`.

## Monorepo Packages (`packages/`)

| Package | Architectural Role |
|---|---|
| `packages/contracts/` | Foundation DTOs, spec/state models, tracing/clock/policy ports |
| `packages/expression/` | Static Arazzo expression lexer, parser, AST, selector plugins |
| `packages/evaluation/` | Runtime expression, criteria, selector, interpolation, payload evaluation |
| `packages/document/` | Arazzo document loading, parsing, validation, input schema validation |
| `packages/sources/` | Source fetching/resolution and base OpenAPI/WSDL normalization |
| `packages/engine/` | Step state machine, retry policy, execution pipeline, OTel adapter |
| `packages/protocol-http/` | HTTP/OpenAPI + AsyncAPI executors, normalizers, response validation |
| `packages/protocol-rpc/` | gRPC, gRPC-Web, Twirp, and Connect protocol vertical slice |
| `packages/protocol-soap/` | SOAP executor, response mapper, and XSD response validator |
| `packages/runner/` | Sync (`Runner`) and durable (`AsyncRunner`) workflow execution facades |
| `packages/cli/` | `arazzo` CLI binary (`run`, `validate`, `list`, `explain`, `render`) |
| `packages/core/` | Aggregator metapackage, cross-cutting architecture/conformance tests, custom PHPStan rules |
