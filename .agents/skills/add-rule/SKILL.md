---
name: add-rule
description: Scaffold a new Arazzo validator rule plugin (`#[AsValidationRulePlugin]` class + Pest test) in packages/document. Use when adding a validation rule, error/warning code, or spec-conformance check.
---

# Add Validator Rule

Validation rules live in `packages/document/src/Plugins/ValidationRule/`, carry `#[AsValidationRulePlugin]`, and are auto-discovered by `ValidationRuleRegistry::create()` — no manual `RuleSet::default()` registration is required.

## Step 1 — Generate

```bash
php .agents/skills/add-rule/scripts/new-rule.php <Name> <dotted.code>
```

- **Name**: PascalCase or kebab-case; the `Rule` suffix is added automatically.
- **Code**: dotted `lower_snake` segments matching existing codes (`source.unique_name`, `step.http_missing_success_criteria`, `workflow.inputs_valid_schema`).
- Pass `--dry-run` to preview without writing. The script refuses to overwrite existing files.

## Step 2 — Implement

Open `packages/document/src/Plugins/ValidationRule/<Class>.php` and implement `check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors)`:

- Emit hard violations via `$errors->error($this->code(), "<message>", "/json/pointer")` and `SHOULD`-level lints via `$errors->warning(...)` (callers promote or suppress rules via `ValidatorPolicy`).
- Read spec data through typed DTOs (`$doc->workflows`, `$doc->sourceDescriptions`, …), never raw arrays.
- Add failing and passing cases to `packages/document/tests/Validator/Rules/<Class>Test.php`, keeping the minimal-document no-op baseline.

## Step 3 — Verify

```bash
vendor/bin/pint packages/document/src/Plugins/ValidationRule/<Class>.php packages/document/tests/Validator/Rules/<Class>Test.php
vendor/bin/pest packages/document/tests/Validator/Rules/<Class>Test.php
composer run analyse-document
composer run phpmd
```

Completion criterion: new tests pass (no-op, passing, and violation cases), `RuleSet::default()` auto-discovers the rule code, `composer run analyse-document` and `composer run phpmd` pass with zero errors.
