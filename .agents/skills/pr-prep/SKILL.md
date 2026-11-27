---
name: pr-prep
description: Prepare a PR from the current branch — run gates, regenerate docs, draft title and body with issue linkage. Use when opening a pull request or asking to prep/draft a PR.
---

# Prep Pull Request

One command from feature branch to reviewable draft. The script guards against running on `main`, warns about uncommitted changes, runs the quality gates, and drafts the PR body from real commit subjects plus a diff stat.

## Run

```bash
bash .agents/skills/pr-prep/scripts/pr-prep.sh [base] [--create] [--no-gates]
```

- **Remote & base**: Use `upstream/main` (`PHP-Arazzo/php-arazzo`) when `upstream` is configured, otherwise `origin/main`.
- **Architecture docs**: Run `composer run docs` (`php scripts/generate-docs.php`) before pushing whenever namespaces, rules, commands, or public APIs changed.
- **Issue linkage gate**: `.github/scripts/pr-linkage-gate.sh` fails PRs with no closing reference. Include `Closes #<issue>` in the PR body.
- **Body**: Lands in `.scratch/pr-body.md` and prints to stdout — read and rewrite commit subjects into concise prose before creating.
- `--create` opens a **draft** PR via `gh`, refusing when any gate failed.
- `--no-gates` skips pint/phpstan/pest for WIP branches — never on a branch someone else will review.

Completion criterion: gates pass, `docs/generated/` is up to date, body reads as prose with `Closes #<issue>`, and the PR opens against the right base on `PHP-Arazzo/php-arazzo`.
