# php-arazzo/arazzo-cli

Command-line interface and delivery adapters for executing, validating, and inspecting [Arazzo](https://spec.openapis.org/arazzo/latest.html) workflow specifications in PHP.

[![PHP](https://img.shields.io/badge/PHP-^8.4-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](../../LICENSE.md)

`arazzo-cli` provides the standalone `arazzo` executable binary, powered by Symfony Console, allowing developers and CI/CD pipelines to validate documents, execute workflows, list workflows, explain dependency chains, and render workflows as Markdown or Mermaid diagrams.

---

## Installation

### Local Project Dependency (Recommended)

Install as a development dependency in your project:

```bash
composer require --dev php-arazzo/arazzo-cli
```

Composer installs the executable into `./vendor/bin/arazzo`.

### Global Installation

Install globally to use `arazzo` as a system-wide CLI utility:

```bash
composer global require php-arazzo/arazzo-cli
```

Ensure Composer's global bin directory (typically `~/.composer/vendor/bin` or `%APPDATA%\Composer\vendor\bin`) is in your system `$PATH`.

---

## Commands & Usage

```bash
vendor/bin/arazzo [command] [options] [arguments]
```

### 1. Validate an Arazzo Document

Lint-checks an Arazzo document against specification rules, structural schemas, parameter actions, and expression grammar:

```bash
vendor/bin/arazzo validate path/to/workflow.arazzo.yaml
```

Exits with `0` when valid, or `1` with formatted diagnostic errors when invalid.

### 2. Run a Workflow

Executes a workflow: resolves operations, evaluates success criteria, applies retry/goto logic, and outputs step execution results:

```bash
# Run the first workflow in the document
vendor/bin/arazzo run path/to/workflow.arazzo.yaml

# Run a specific workflow by workflowId
vendor/bin/arazzo run path/to/workflow.arazzo.yaml --workflow=createPetFlow

# Pass runtime JSON inputs
vendor/bin/arazzo run path/to/workflow.arazzo.yaml --input='{"petId": "123", "tag": "vip"}'
```

### 3. List Workflows

Inspect all workflow identifiers and step targets defined in an Arazzo specification:

```bash
vendor/bin/arazzo list-workflows path/to/workflow.arazzo.yaml
```

### 4. Explain Execution Graph

Display the topological execution order, dependencies (`dependsOn`), success criteria, and step actions without executing side effects:

```bash
vendor/bin/arazzo explain path/to/workflow.arazzo.yaml --workflow=orderCheckout
```

### 5. Render Documentation & Flowcharts

Generate visual Mermaid diagrams or formatted Markdown documentation from workflow definitions:

```bash
# Render a Mermaid flowchart
vendor/bin/arazzo render path/to/workflow.arazzo.yaml --format=mermaid

# Render Markdown documentation
vendor/bin/arazzo render path/to/workflow.arazzo.yaml --format=markdown

# Save rendered output directly to a file
vendor/bin/arazzo render path/to/workflow.arazzo.yaml --format=mermaid --output=docs/workflow.mmd
```

---

## Programmatic Usage

You can also embed the console application into custom CLI toolchains:

```php
use Alama\Arazzo\Cli\Console\Application;

$app = new Application();
$app->run();
```
