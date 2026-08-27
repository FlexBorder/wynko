#!/usr/bin/env bash
#
# Wynko merge gate — the sanctioned way to merge a branch into main.
#
# Runs a full local mirror of every CI job (build's PHP 8.0-8.5 matrix,
# security's PHPCS-security + Semgrep, javascript's JS+CSS lint,
# plugin-check's wp-org-check + Plugin Check, sbom's full regen with the
# same pinned npm CI uses) against the branch, then requires an explicit
# confirmation that /security-review (Claude Code) ran on the branch and
# its findings were resolved, before recording that attestation as a
# `Security-Reviewed: <branch-tip-sha>` trailer on the merge commit. CI's
# security-review-attestation job rejects any merge commit reaching main
# without a matching trailer — see CONTRIBUTING.md. That job is the one
# thing this script can't mirror: it verifies, after the fact, that this
# very script was used — it can't check itself.
#
# Usage: bin/merge-to-main.sh <branch>
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

fail() {
	printf 'merge-to-main: %s\n' "$1" >&2
	exit 1
}

[ "$#" -eq 1 ] || fail "usage: bin/merge-to-main.sh <branch>"
BRANCH="$1"

[ -z "$(git status --porcelain)" ] || fail "working tree is dirty — commit or stash first."
[ "$(git branch --show-current)" = "main" ] || fail "switch to main first: git switch main"
git rev-parse --verify --quiet "$BRANCH" >/dev/null || fail "unknown branch: $BRANCH"

BRANCH_SHA="$(git rev-parse "$BRANCH")"

[ -f vendor/bin/phpunit ] || fail "vendor/bin/phpunit not found — run 'composer install' first."

printf 'merge-to-main: checking out %s to run the full gate\n' "$BRANCH"
git switch --quiet "$BRANCH"

gate_failed=0

echo "== build (PHP 8.0-8.5 matrix: PHPUnit, PHPCS, PHPStan) =="
bin/php-matrix.sh || gate_failed=1

echo "== security (PHPCS security ruleset) =="
bin/security-scan.sh || gate_failed=1

echo "== security (Semgrep) =="
bin/semgrep-scan.sh || gate_failed=1

echo "== javascript (ESLint) =="
bin/js-lint.sh || gate_failed=1

echo "== javascript (stylelint) =="
bin/style-lint.sh || gate_failed=1

echo "== plugin-check (WordPress.org readiness) =="
bin/wp-org-check.sh || gate_failed=1

# CI's plugin-check job installs production-only dependencies before running
# Plugin Check ("matches the shipped ZIP" — see .github/workflows/ci.yml).
# The php-matrix step above just left vendor/ full of dev tooling (PHPUnit,
# PHPCS, PHPStan, Mockery, …) for six PHP versions; scanning that instead of
# a production vendor/ makes Plugin Check's strict, all-categories run scan
# far more than CI does, which was driving it to exhaust host memory. Swap to
# a --no-dev vendor/ just for this step; the sbom step below reinstalls with
# dev deps anyway, and vendor/ is git-ignored so nothing downstream depends
# on this being restored.
echo "== plugin-check: swapping to a production-only vendor/ (matches CI) =="
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
	-v "$PWD":/app -w /app composer:2 \
	install --no-dev --no-interaction --optimize-autoloader || gate_failed=1

echo "== plugin-check (Plugin Check, all categories, strict) =="
bin/plugin-check.sh || gate_failed=1

echo "== sbom (full regen, pinned npm) =="
npm install -g npm@11.16.0 || gate_failed=1
bin/sbom-check.sh --regenerate || gate_failed=1

git switch --quiet main

[ "$gate_failed" -eq 0 ] || fail "the local gate failed on $BRANCH — fix it before merging."

printf 'Have you run /security-review on %s at %s and resolved every finding? [y/N] ' \
	"$BRANCH" "$BRANCH_SHA"
read -r confirmation
[ "$confirmation" = "y" ] || fail "merge cancelled — run /security-review first."

git merge --no-ff "$BRANCH" -m "$(printf 'Merge branch '\''%s'\'' into main\n\nSecurity-Reviewed: %s' "$BRANCH" "$BRANCH_SHA")"
git branch -d "$BRANCH"

printf 'merge-to-main: merged %s into main (Security-Reviewed: %s)\n' "$BRANCH" "$BRANCH_SHA"
