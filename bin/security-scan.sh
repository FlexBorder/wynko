#!/usr/bin/env bash
#
# Wynko security scan — runs the security-only PHPCS ruleset (WordPress.Security.*)
# as a hard gate. Used by the .githooks/pre-commit hook and reused in CI.
#
# Uses local PHP when available, otherwise the pinned Docker image (this project
# has no required local PHP toolchain — see CONTRIBUTING.md). Semgrep runs
# separately, via bin/semgrep-scan.sh.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -f vendor/bin/phpcs ]; then
	echo "security-scan: vendor/bin/phpcs not found — run 'composer install' first." >&2
	exit 1
fi

# Run a PHP command line either with local PHP or via Docker.
run() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif command -v docker >/dev/null 2>&1; then
		docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli php "$@"
	else
		echo "security-scan: requires local PHP or Docker." >&2
		exit 1
	fi
}

echo "== Wynko security scan (PHPCS WordPress.Security.*) =="
echo "-- Sniffs in this ruleset --"
run vendor/bin/phpcs -e --standard=phpcs-security.xml.dist
echo
echo "-- Per-file results (0/0 = clean) --"
run vendor/bin/phpcs --standard=phpcs-security.xml.dist --report=summary -v
echo "security-scan: OK"
