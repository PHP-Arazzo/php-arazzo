# Implementation Plan: Update Laravel Package for New Public Interface

Source spec: Public API refactoring across all monorepo packages (runner-public-interface-design, PR #460)

## Overview

The Laravel package (`/Users/mohammedalama/Code/PHPArazzo/laravel-arazzo`) references classes/interfaces that were removed, renamed, or moved during the public API refactoring. It needs to be updated to match the new public interface.

Key API changes:
- `AsyncExecutionGraph` → `AsyncRunner` / `AsyncExecutionGraphAssembler`
- `RunnerFacade`/`RunnerGraphBuilder`/`OperationRuntime` → `Runner` / `AsyncRunner`
- `SourceGraph`/`SourceRuntime`/`SourceRegistry` → `Sources`
- `SelectorPluginRegistryInterface` → `SelectorPluginRegistry` class
- `EventLedgerInterface`/`LedgerEventListener` → removed (consolidated into `ExecutionStoreInterface`)
- `StateStoreInterface`+`ExecutionRegistryInterface`+`PendingCorrelationRegistryInterface`+`DefinitionRegistryInterface` → `ExecutionStoreInterface`+`DefinitionStoreInterface`
- `OpenApiOperationResolver` moved from `Sources\Normalizer\` to `Protocol\Http\Resolver\`
- `ExecuteStepJob` moved from `Runner\Async\` to `Runner\Data\`
- `ResumeCorrelationJob` → removed

## Architecture Decisions
- Use `AsyncRunner`/`Runner` as the public facade bindings instead of removed facade classes
- Create `DatabaseExecutionStore` implementing `ExecutionStoreInterface` (replaces separate state/ledger/registry/pending-correlation classes)
- Create `DatabaseDefinitionStore` implementing `DefinitionStoreInterface` (replaces `DatabaseDefinitionRegistry`)
- Use `Sources` class instead of `SourceGraph`/`SourceRuntime`
- Remove `EventLedgerInterface` dependency entirely (no replacement in contracts)
- `AsyncGraphResolver` and service providers reference many more removed classes — may need restructuring

## Task List

### Phase 1: Simple namespace/class reference fixes
- [ ] Task 1: Fix `LaravelQueueDriver.php` — `ExecuteStepJob` namespace, remove `ResumeCorrelationJob` case
- [ ] Task 2: Fix `RunExecuteStepJob.php` — `ExecuteStepJob` namespace, replace `AsyncExecutionGraph`
- [ ] Task 3: Fix `RunResumeCorrelationJob.php` — `ResumeCorrelationJob` doesn't exist, replace `AsyncExecutionGraph`
- [ ] Task 4: Fix `WebhookResumeController.php` — `ResumeCorrelationJob` doesn't exist

### Checkpoint: Phase 1
- [ ] Queue jobs and driver reference correct classes

### Phase 2: Binding replacements
- [ ] Task 5: Fix `EventBindings.php` — remove `EventLedgerInterface` + `LedgerEventListener`
- [ ] Task 6: Fix `ExecutionBindings.php` — replace `AsyncExecutionGraph` with `AsyncRunner`
- [ ] Task 7: Fix `FacadeBindings.php` — replace removed facade/source classes
- [ ] Task 8: Fix `PersistenceBindings.php` — replace old interfaces with `ExecutionStoreInterface`+`DefinitionStoreInterface`
- [ ] Task 9: Fix `ResolverBindings.php` — replace removed source classes with `Sources`

### Checkpoint: Phase 2
- [ ] All bindings reference existing classes/interfaces

### Phase 3: Create new adapter classes
- [ ] Task 10: Create `DatabaseExecutionStore` implementing `ExecutionStoreInterface`
- [ ] Task 11: Create `DatabaseDefinitionStore` implementing `DefinitionStoreInterface`

### Checkpoint: Phase 3
- [ ] New adapter classes implement correct interfaces

### Phase 4: Fix support classes and service providers
- [ ] Task 12: Fix `AsyncGraphResolver.php` — references many removed classes
- [ ] Task 13: Fix `ProtocolHttpServiceProvider.php` — references removed classes
- [ ] Task 14: Fix `SoapServiceProvider.php` — references removed classes
- [ ] Task 15: Fix `RpcServiceProvider.php` — references removed classes

### Checkpoint: Phase 4
- [ ] All service providers reference existing classes

### Phase 5: Verify
- [ ] Task 16: Run Laravel package tests to verify changes

## Risks and Mitigations
| Risk | Impact | Mitigation |
|------|--------|------------|
| Many service provider classes removed | High | May need to simplify/restructure providers |
| `EventLedgerInterface` removed with no replacement | High | Remove event ledger binding entirely |
| Tests may reference old class names | Medium | Update tests alongside source |

## Open Questions
- Should `AsyncGraphResolver` be kept or removed? It references `AsyncGraphSeams`, `RunnerGraphBuilderInterface`, `EventLedgerInterface`, `OperationExecutorRegistry` — all removed.
- Should `ProtocolHttpServiceProvider`, `SoapServiceProvider`, `RpcServiceProvider` be simplified given many referenced classes are removed?
