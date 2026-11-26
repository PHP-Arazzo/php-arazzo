# Laravel Package Refactoring — Todo List (DONE)

## Phase 1: Simple namespace/class reference fixes (DONE)
- [x] Task 1: Fix `LaravelQueueDriver.php` — `ExecuteStepJob` namespace, remove `ResumeCorrelationJob` case
- [x] Task 2: Fix `RunExecuteStepJob.php` — `ExecuteStepJob` namespace, replace `AsyncExecutionGraph`
- [x] Task 3: Fix `RunResumeCorrelationJob.php` — replace `ResumeCorrelationJob` + `AsyncExecutionGraph`
- [x] Task 4: Fix `WebhookResumeController.php` — remove `ResumeCorrelationJob`

## Phase 2: Binding replacements (DONE)
- [x] Task 5: Fix `EventBindings.php` — remove `EventLedgerInterface` + `LedgerEventListener`
- [x] Task 6: Fix `ExecutionBindings.php` — replace `AsyncExecutionGraph` with `AsyncRunner`
- [x] Task 7: Fix `FacadeBindings.php` — replace removed facade/source classes
- [x] Task 8: Fix `PersistenceBindings.php` — replace old interfaces with `ExecutionStoreInterface`+`DefinitionStoreInterface`
- [x] Task 9: Fix `ResolverBindings.php` — removed (no longer needed)

## Phase 3: Create new adapter classes (DONE)
- [x] Task 10: Create `DatabaseExecutionStore` implementing `ExecutionStoreInterface`
- [x] Task 11: Create `DatabaseDefinitionStore` implementing `DefinitionStoreInterface`

## Phase 4: Fix support classes and service providers (DONE)
- [x] Task 12: Fix `AsyncGraphResolver.php` — references many removed classes
- [x] Task 13: Fix `ProtocolHttpServiceProvider.php` — references removed classes
- [x] Task 14: Fix `SoapServiceProvider.php` — references removed classes
- [x] Task 15: Fix `RpcServiceProvider.php` — references removed classes

## Phase 5: Tooling (DONE)
- [x] Create `Makefile` with `test`, `test-coverage`, `format`, `format-test`, `analyse`, `phpmd`, `verify`, `hooks-install`
- [x] Create `.githooks/pre-commit` (pint --test + phpstan + pest)
- [x] Fix `composer.json` (removed stale `events`/`runtime` path repos and requires)
- [x] Fix `phpstan.neon.dist` (correct vendor paths)

## Phase 6: Tests (DONE)
- [x] Update all existing tests for new public API
- [x] Create `DatabaseExecutionStoreTest.php`
- [x] Create `RunResumeCorrelationJobTest.php`
- [x] Create `AsyncGraphResolverTest.php`
- [x] Create `SchemeSourceFetcherTest.php`
- [x] Simplify `SoapServiceProviderTest.php`, `RpcServiceProviderTest.php`, `ProtocolHttpServiceProviderTest.php`
- [x] Simplify `ResolverBindingsTest.php` → removed (class deleted)
- [x] Simplify `StrictValidationDefaultTest.php`, `EventBindingsTest.php`, etc.

## Verification (ALL PASS)
- [x] Pint: PASS (code style)
- [x] PHPStan: PASS (level: max, no errors)
- [x] Pest: 166 passed, 1 deprecated (270 assertions)
- [x] Coverage: 93.1% (only `RunResumeCorrelationJob::handle()` at 50% — integration boundary, `AsyncRunner` is `final`)
