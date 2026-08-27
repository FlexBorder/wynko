#!/usr/bin/env bash
#
# Wynko static analysis — runs the single-version PHPStan check as a hard
# gate. Used by the .githooks/pre-commit hook. bin/php-matrix.sh re-runs this
# across every supported PHP version at merge time.
#
# Uses local PHP when available, otherwise the pinned Docker image (this
# project has no required local PHP toolchain — see CONTRIBUTING.md).
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -f vendor/bin/phpstan ]; then
	echo "static-analysis: vendor/bin/phpstan not found — run 'composer install' first." >&2
	exit 1
fi

run() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif command -v docker >/dev/null 2>&1; then
		docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli php "$@"
	else
		echo "static-analysis: requires local PHP or Docker." >&2
		exit 1
	fi
}

echo "== Wynko static analysis (PHPStan) =="
run vendor/bin/phpstan analyse --memory-limit=1G
echo "static-analysis: OK"
