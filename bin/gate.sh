#!/usr/bin/env bash
#
# Wynko branch gate — the full single-version check suite, run once per
# branch rather than on every commit. This is the first step of
# bin/merge-to-main.sh; run it yourself before requesting a merge to get the
# same signal earlier.
#
# Holds everything the fast pre-commit tier (bin/write-report.sh) leaves out:
# the full-tree PHPCS (WordPress + security rulesets), PHPStan, the unit
# suite, JS/CSS lint, Semgrep, the WordPress.org readme guard, Plugin Check
# against a production-only vendor/, and a full SBOM regeneration. One PHP
# version (whatever bin/*.sh resolve to locally, or php:8.5-cli via Docker);
# bin/php-matrix.sh covers PHP 8.0-8.5 separately, also at merge time.
#
# Runs every check even after one fails, then exits non-zero if any did.
#
# Usage: bin/gate.sh
#
set -uo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

if ! command -v docker >/dev/null 2>&1; then
	echo "gate: requires Docker (Plugin Check, Semgrep, SBOM, vendor swap)." >&2
	exit 1
fi

composer_run() {
	docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
		-v "$PWD":/app -w /app composer:2 "$@"
}

# Plugin Check (and the SBOM regen) need vendor/ to look like the shipped
# ZIP: production dependencies only. Restore the dev toolchain on exit
# however this script ends — vendor/ is git-ignored, so nothing downstream
# depends on the swapped state, but leaving a dev machine without PHPUnit
# would be a nasty surprise.
restore_dev_vendor() {
	echo "== gate: restoring the dev vendor/ =="
	composer_run install --no-interaction || true
}
trap restore_dev_vendor EXIT

gate_failed=0

echo "== gate: security scan (PHPCS security ruleset) =="
bin/security-scan.sh || gate_failed=1

echo "== gate: coding standards (PHPCS WordPress, full tree) =="
bin/coding-standards.sh || gate_failed=1

echo "== gate: static analysis (PHPStan, full tree) =="
bin/static-analysis.sh || gate_failed=1

echo "== gate: unit tests (PHPUnit) =="
bin/unit-tests.sh || gate_failed=1

echo "== gate: JS coding standards (ESLint) =="
bin/js-lint.sh || gate_failed=1

echo "== gate: CSS/SCSS coding standards (stylelint) =="
bin/style-lint.sh || gate_failed=1

echo "== gate: Semgrep (OWASP Top 10 + PHP) =="
bin/semgrep-scan.sh || gate_failed=1

echo "== gate: WordPress.org readiness =="
bin/wp-org-check.sh || gate_failed=1

echo "== gate: swapping to a production-only vendor/ (matches the shipped ZIP) =="
composer_run install --no-dev --no-interaction --optimize-autoloader || gate_failed=1

echo "== gate: Plugin Check (all categories, strict) =="
bin/plugin-check.sh || gate_failed=1

echo "== gate: SBOM freshness (full regen, pinned npm) =="
npm install -g npm@11.16.0 || gate_failed=1
bin/sbom-check.sh --regenerate || gate_failed=1

if [ "$gate_failed" -eq 0 ]; then
	echo "gate: OK"
else
	echo "gate: FAIL — see findings above." >&2
fi

exit "$gate_failed"
