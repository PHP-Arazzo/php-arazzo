#!/usr/bin/env bash
#
# Clean-installation smoke test: installs monorepo packages into a
# throwaway Composer project using path repositories, verifies autoload,
# and verifies vendor/bin/arazzo CLI execution.
#
# Usage: bash scripts/smoke-install.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d /tmp/arazzo-smoke.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

echo "==> smoke project: $TMP"
cd "$TMP"

# Point path repositories straight at the monorepo package directories so
# PSR-4 autoload roots resolve to their real src/ trees.
cat > composer.json <<JSON
{
    "name": "smoke/smoke-test",
    "require": {
        "php-arazzo/arazzo-core": "@dev",
        "php-arazzo/arazzo-cli": "@dev"
    },
    "repositories": [
        {"type": "path", "url": "$REPO_ROOT/packages/cli", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/contracts", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/core", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/document", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/engine", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/evaluation", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/expression", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/protocol-http", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/protocol-rpc", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/protocol-soap", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/runner", "options": {"symlink": true}},
        {"type": "path", "url": "$REPO_ROOT/packages/sources", "options": {"symlink": true}}
    ],
    "minimum-stability": "dev"
}
JSON

composer install --quiet --no-interaction --no-progress

# Verify CLI binary is installed in vendor/bin and executes
php vendor/bin/arazzo --version
php vendor/bin/arazzo validate --help

php -r '
require $argv[1]."/vendor/autoload.php";

use Alama\Arazzo\Cli\Console\Application;
use Alama\Arazzo\Document\Parser\Parser;

$app = new Application();
echo "CLI application: ", $app->getName(), " v", $app->getVersion(), "\n";

$parser = new Parser();
echo "Parser loaded: ", get_class($parser), "\n";
' "$TMP"

echo "==> smoke OK"
