#!/usr/bin/env bash
#
# Wynko merge gate — the sanctioned way to merge a branch into main.
#
# Runs the branch gate (bin/gate.sh — full-tree PHPCS + security ruleset,
# PHPStan, unit suite, JS/CSS lint, Semgrep, wp-org-check, Plugin Check
# against a production vendor/, full SBOM regen) and the PHP 8.0-8.5 matrix
# (bin/php-matrix.sh), then requires an explicit confirmation that
# /security-review (Claude Code) ran on the branch and its findings were
# resolved, before recording that attestation as a
# `Security-Reviewed: <branch-tip-sha>` trailer on the merge commit.
#
# These checks are not re-run per commit — the pre-commit hook is a fast
# staged-file tier (see .githooks/pre-commit). This script is where the
# heavy, full-scope work happens, once per branch. See TECHNICAL_DEBT.md
# TD-073.
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

echo "== PHP 8.0-8.5 matrix (PHPUnit, PHPStan per version; PHPCS once) =="
bin/php-matrix.sh || gate_failed=1

echo "== branch gate (full-tree standards, Semgrep, Plugin Check, SBOM) =="
bin/gate.sh || gate_failed=1

git switch --quiet main

[ "$gate_failed" -eq 0 ] || fail "the local gate failed on $BRANCH — fix it before merging."

# The confirmation must come from a human. Read it from the controlling
# terminal, not stdin — a `docker`/`npm` call in the gate above can drain a
# piped stdin, which would silently skip the prompt. For automation, set
# WYNKO_SECURITY_REVIEWED to the exact branch-tip SHA being merged (so it
# cannot be a stale blanket bypass) instead of piping an answer.
if [ "${WYNKO_SECURITY_REVIEWED:-}" = "$BRANCH_SHA" ]; then
	printf 'merge-to-main: security review pre-confirmed for %s via WYNKO_SECURITY_REVIEWED\n' "$BRANCH_SHA"
elif [ -r /dev/tty ]; then
	printf 'Have you run /security-review on %s at %s and resolved every finding? [y/N] ' \
		"$BRANCH" "$BRANCH_SHA"
	read -r confirmation </dev/tty
	[ "$confirmation" = "y" ] || fail "merge cancelled — run /security-review first."
else
	fail "no terminal for the /security-review confirmation — set WYNKO_SECURITY_REVIEWED=$BRANCH_SHA to confirm non-interactively."
fi

git merge --no-ff "$BRANCH" -m "$(printf 'Merge branch '\''%s'\'' into main\n\nSecurity-Reviewed: %s' "$BRANCH" "$BRANCH_SHA")"
git branch -d "$BRANCH"

printf 'merge-to-main: merged %s into main (Security-Reviewed: %s)\n' "$BRANCH" "$BRANCH_SHA"
