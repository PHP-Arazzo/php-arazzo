# ADR-012: Validator Policy and Collector Severity Interception

## Status

Accepted

## Context

php-arazzo has over 50 validation rules registered via `#[AsValidationRulePlugin]` across multiple packages (`document`, `expression`, `protocol-soap`, `protocol-rpc`) and third-party plugins. Rules directly call `$errors->error(...)` or `$errors->warning(...)`. Users and CI workflows needed the ability to configure rule severities (demote errors to warnings) and disable rules dynamically without changing the code of every rule or breaking `ValidationRuleInterface`.

## Decisions

1. **`ValidatorPolicy` Value Object in `contracts`**:
   `Alama\Arazzo\Contracts\Validation\ValidatorPolicy` is introduced in `packages/contracts` following the ADR-010 `*Policy` standard (`final readonly`, `::default()`, immutable `with*()`). It holds per-rule severity overrides (`array<string, Severity>`), disabled rules (`list<string>`), and strict mode (`bool`). Placing it in `contracts` allows `ErrorCollector`, `document`, and `cli` to share the contract without cyclic dependencies.

2. **Collector-Level Severity Interception**:
   `ErrorCollector` accepts an optional `ValidatorPolicy`. When a rule invokes `$errors->error($code, ...)`, if `$policy->severityFor($code) === Severity::Warning`, the collector automatically records a `Warning` instead of an `Error`. Rules remain completely decoupled from policy machinery, avoiding breaking `ValidationRuleInterface::check()` or requiring updates to 50+ existing rules.

3. **RuleSet and Document Integration**:
   `RuleSet` accepts `ValidatorPolicy` exclusively (retiring the separate `$disabled` list and `$strict` boolean parameters), using it to filter `activeRules()` by disabled rule codes and provide strictness. `Document::withValidatorPolicy(ValidatorPolicy $policy)` configures the validation pipeline.

4. **Bidirectional Severity Remapping in ErrorCollector**:
   Severity overrides are bidirectional: rules calling `error()` can be demoted to `Warning`, and rules calling `warning()` can be elevated to `Error`. Diagnostics passed to `ErrorCollector::add()` are also normalized through the policy.

5. **CLI Flags and Config File Precedence**:
   `ValidateCommand` accepts repeatable `--severity <code>:<level>`, repeatable `--disable-rule <code>`, `--strict`/`--no-strict`, and loads configuration from `arazzo.validate.php` or `arazzo.json`, with CLI flags overriding file configuration. Exit code reflects only `$result->isValid()` (`0` on success or warnings only, `1` on errors).

## Consequences

### Positive
- Zero changes required to the 50+ existing validation rules across all packages.
- Backward compatibility preserved for `ValidationRuleInterface`.
- Full CLI and programmatic configurability of validator rule severity and active rules.

### Negative / Trade-offs
- Demoting a rule occurs inside `ErrorCollector`, meaning rule implementations are unaware of their demoted status during `check()`.
