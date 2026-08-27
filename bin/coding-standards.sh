#!/usr/bin/env bash
#
# Wynko coding-standards check — runs PHPCS against the WordPress Coding
# Standards ruleset (phpcs.xml.dist). Used by the .githooks/pre-commit hook and
# reused in CI. Run `composer lint:fix` to auto-correct what PHPCBF can.
#
# Uses local PHP when available, otherwise the pinned Docker image (this project
# has no required local PHP toolchain — see CONTRIBUTING.md).
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -f vendor/bin/phpcs ]; then
	echo "coding-standards: vendor/bin/phpcs not found — run 'composer install' first." >&2
	exit 1
fi

run() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif command -v docker >/dev/null 2>&1; then
		docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli php "$@"
	else
		echo "coding-standards: requires local PHP or Docker." >&2
		exit 1
	fi
}

echo "== Wynko coding standards (PHPCS WordPress) =="
echo "-- Ruleset size --"
ruleset_size="$(run vendor/bin/phpcs -e)"
echo "${ruleset_size%%$'\n'*}"
echo
echo "-- Per-file results (0/0 = clean) --"
run vendor/bin/phpcs --report=summary -v
echo "coding-standards: OK"
