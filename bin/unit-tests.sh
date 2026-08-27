#!/usr/bin/env bash
#
# Wynko unit tests — runs the single-version PHPUnit suite as a hard gate.
# Used by the .githooks/pre-commit hook. bin/php-matrix.sh re-runs this
# across every supported PHP version at merge time.
#
# Uses local PHP when available, otherwise the pinned Docker image (this
# project has no required local PHP toolchain — see CONTRIBUTING.md).
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -f vendor/bin/phpunit ]; then
	echo "unit-tests: vendor/bin/phpunit not found — run 'composer install' first." >&2
	exit 1
fi

run() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif command -v docker >/dev/null 2>&1; then
		docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli php "$@"
	else
		echo "unit-tests: requires local PHP or Docker." >&2
		exit 1
	fi
}

echo "== Wynko unit tests (PHPUnit) =="
run vendor/bin/phpunit
echo "unit-tests: OK"
