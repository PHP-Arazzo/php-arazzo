# ADR-008: Uniform `As<Name>Plugin` Attributes and `src/Plugins/<Name>` Directory Convention

## Status

Accepted — 2026-11-23 (extends [ADR-007](./ADR-007-expression-package-public-interface-and-plugin-registries.md))

## Context

Following [ADR-007](./ADR-007-expression-package-public-interface-and-plugin-registries.md), all extensible subsystems in PHP-Arazzo use PHP 8 attributes paired with Composer's `extra.arazzo.plugins` manifest for auto-discovery (`Alama\Arazzo\Contracts\Discovery\PluginDiscovery`).

During adoption across the monorepo, four structural inconsistencies emerged:

1. **Asymmetric attribute naming**: Three attributes carried the `Plugin` suffix (`AsSelectorPlugin`, `AsExpressionPlugin`, `AsCriterionPlugin`) while three omitted it (`AsSourceNormalizer`, `AsOperationExecutor`, `AsValidationRule`), making it unclear at a glance which attributes belong to the plugin discovery system.
2. **Scattered plugin directories**: Concrete plugin implementations lived in package-specific feature directories (`src/Validator/Rules`, `src/Normalizer`, `src/Protocol`, `src/Execution`, `src/Validation`, `src/Sync`, and flat `src/Plugins/`), mixing discoverable plugins with internal parsers, DTOs, helpers, and unattributed opt-in classes.
3. **Manifest friction**: Listing individual plugin FQCNs in `composer.json` created maintenance churn every time a rule or plugin was added, while pointing `extra.arazzo.plugins` at broad feature directories forced `PluginManifestScanner` to inspect unrelated internal files.
4. **Hardcoded tooling assumptions**: Doc generators and audits assumed rules lived in a single package directory (`Validator/Rules`) rather than being contributed by any package via attributes.

## Decisions

### 1. Uniform `As<Name>Plugin` Attribute Naming (Enum-Keyed, No Numeric Priority)

Every plugin registration attribute in `Alama\Arazzo\Contracts\Attributes\*` MUST be named `As<Name>Plugin` and carry a typed domain Enum — never numeric `int $priority` arguments. Registries resolve overrides via **last-registered-wins** semantics keyed by the Enum (or rule code for validation rules).

### 2. 1:1 `src/Plugins/<Name>/` Directory and Namespace Convention

Every concrete class annotated with `#[As<Name>Plugin]` MUST live in `src/Plugins/<Name>/` under namespace `<PackageNamespace>\Plugins\<Name>\*`, where `<Name>` matches the attribute name stripped of the `As` prefix and `Plugin` suffix.

- Internal collaborators, parsers, DTOs (`src/Data/`), and support utilities (`src/Support/`) stay outside `src/Plugins/<Name>/`.
- Intentionally unattributed or opt-in classes (e.g. `StepSuccessActionParameterInRule`) MUST NOT be placed in `src/Plugins/<Name>/` so they are never confused with auto-discovered plugins.
- In-house plugins inside the owning package (such as expression validation rules in `packages/expression/src/Plugins/ValidationRule/`) MUST compose internal package collaborators directly via constructor injection rather than calling their own package's public facade (`ExpressionEngineInterface`).

### 3. Canonical Plugin Taxonomy

| Attribute (`Alama\Arazzo\Contracts\Attributes\*`) | Typed Enum Key | Plugin Directory | Current Package Owners | Registry / Consumer |
|---|---|---|---|---|
| `#[AsSelectorPlugin]` | `ExpressionType` | `src/Plugins/Selector` | `expression` | `SelectorPluginRegistry` (`@internal`) |
| `#[AsExpressionPlugin]` | `ExpressionType` | `src/Plugins/Expression` | `evaluation` | `ExpressionEvaluatorRegistry` |
| `#[AsCriterionPlugin]` | `CriterionType` | `src/Plugins/Criterion` | `evaluation` | `CriterionEvaluatorRegistry` |
| `#[AsSourceNormalizerPlugin]` | `SourceType` | `src/Plugins/SourceNormalizer` | `sources`, `protocol-soap`, `protocol-rpc` | `SourceNormalizerRegistry` |
| `#[AsValidationRulePlugin]` | `ValidationPhase` | `src/Plugins/ValidationRule` | `document`, `expression`, `protocol-soap`, `protocol-rpc` | `ValidationRuleRegistry` |
| `#[AsOperationExecutorPlugin]` | `OperationProtocol` | `src/Plugins/OperationExecutor` | `protocol-http`, `protocol-soap`, `protocol-rpc`, `runner` | `OperationExecutorRegistry` / DI bridges |

### 4. Directory-Scoped Composer Manifests (`extra.arazzo.plugins`)

Each package providing plugins MUST list only its `src/Plugins/<Name>` directories in `composer.json` under `extra.arazzo.plugins`:

```json
"extra": {
    "arazzo": {
        "plugins": [
            "src/Plugins/SourceNormalizer",
            "src/Plugins/ValidationRule",
            "src/Plugins/OperationExecutor"
        ]
    }
}
```

Packages MUST NOT enumerate individual plugin FQCNs in `composer.json` and MUST NOT point `extra.arazzo.plugins` at broad non-plugin directories (e.g. `src/` or `src/Execution`). Third-party packages extending PHP-Arazzo follow the same convention.

### 5. Attribute-Driven Tooling Discovery

Code generators and documentation scripts (such as `scripts/generate-docs/ValidatorRulesDoc.php`) MUST discover plugins across all installed packages by their `#[As<Name>Plugin]` attribute rather than hardcoding a single package or legacy directory path.

## Considered Options

- **Option A — Flat `src/Plugins/` directory per package**: Rejected because packages contributing multiple plugin kinds (`expression`, `evaluation`, `protocol-soap`, `protocol-rpc`) would mix unrelated plugin interfaces in one folder, losing visual symmetry with the attributes.
- **Option B — Keep plugins in feature directories (`src/Normalizer`, `src/Execution`, `src/Validator/Rules`)**: Rejected because `extra.arazzo.plugins` then has to scan internal helpers/parsers/DTOs alongside plugins, and readers cannot distinguish extension points from package internals without opening every file.
- **Option C — Explicit class list in `composer.json` `extra.arazzo.plugins`**: Rejected because adding or renaming a single validation rule (66+ rules across 4 packages) required editing `composer.json` and regenerating `composer.lock` every time.

## Consequences

### Positive

- **Zero ambiguity**: Reading an attribute (`#[AsOperationExecutorPlugin]`) immediately tells you its directory (`src/Plugins/OperationExecutor/`) and manifest entry (`"src/Plugins/OperationExecutor"`), and vice versa.
- **Minimal scan surface**: `PluginManifestScanner` only visits dedicated plugin subdirectories, never internal domain logic.
- **Zero-churn plugin additions**: Adding a new rule or plugin to an existing `src/Plugins/<Name>/` directory requires no `composer.json` or `composer.lock` edits.
- **Consistent third-party DX**: External protocol or rule packages mirror the exact layout used by first-party packages.

### Trade-offs / Breaking Changes

- Moving existing concrete plugins changes their namespaces (`Alama\Arazzo\<Pkg>\Plugins\<Name>\*`) and renames three attributes (`AsSourceNormalizer` → `AsSourceNormalizerPlugin`, `AsOperationExecutor` → `AsOperationExecutorPlugin`, `AsValidationRule` → `AsValidationRulePlugin`). All moved concrete plugins are `@internal`, while the three renamed attributes are pre-1.0 contract changes.
- Plugins moved out of feature namespaces require explicit `use` imports for internal helpers that remain in those feature namespaces (e.g. `OpenApi30Normalizer`, `SoapRequestBuilder`, `ProtoParser`).
- Any new plugin kind introduced in the future MUST define a corresponding `As<Name>Plugin` attribute in `packages/contracts/src/Attributes/` and adopt `src/Plugins/<Name>/`.
