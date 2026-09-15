#!/usr/bin/env bash
#
# Wynko PHP-version matrix — runs PHPUnit and PHPStan under every supported
# PHP version (8.0-8.5), in parallel, plus one PHPCS run.
#
# composer.json pins `config.platform.php` to 8.0, so `composer install`
# resolves the same dependency tree regardless of the interpreter it runs
# under — one install, shared by every leg (vendor/ is git-ignored and
# platform-locked, so this is safe). The Composer cache lives in a host
# directory (created by this script, so it is writable under the `-u`
# mapping) mounted into every container, so nothing re-downloads per version.
#
# PHPCS runs once: phpcs.xml.dist sets `testVersion 8.0-`, so
# PHPCompatibilityWP checks the whole declared range from a single run —
# six runs would be byte-identical. PHPStan still runs per version (it reads
# the analysing PHP's version for some inference and phpstan.neon.dist pins
# no `phpVersion`), and PHPUnit runs per version because that is the check
# that genuinely exercises each interpreter.
#
# Used by bin/merge-to-main.sh. See TECHNICAL_DEBT.md TD-074 for the PHPCS
# single-run decision.
#
# Usage: bin/php-matrix.sh
#
set -uo pipefail

cd "$(git rev-parse --show-toplevel)"

if ! command -v docker >/dev/null 2>&1; then
	echo "php-matrix: requires Docker." >&2
	exit 1
fi

VERSIONS=(8.0 8.1 8.2 8.3 8.4 8.5)
CACHE_DIR="${TMPDIR:-/tmp}/wynko-composer-cache"
mkdir -p "$CACHE_DIR"
LOG_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR"' EXIT

php_run() {
	local version="$1"
	shift
	docker run --rm -u "$(id -u):$(id -g)" \
		-e COMPOSER_HOME=/tmp/composer -v "$CACHE_DIR":/tmp/composer \
		-v "$PWD":/app -w /app "php:${version}-cli" "$@"
}

echo "== composer install (platform-locked to PHP 8.0, shared by every leg) =="
if ! docker run --rm -u "$(id -u):$(id -g)" \
	-e COMPOSER_HOME=/tmp/composer -v "$CACHE_DIR":/tmp/composer \
	-v "$PWD":/app -w /app composer:2 \
	install --no-interaction --no-progress; then
	echo "php-matrix: composer install failed." >&2
	exit 1
fi

echo "== PHPUnit + PHPStan across PHP ${VERSIONS[*]} (parallel) =="
pids=()
for version in "${VERSIONS[@]}"; do
	{
		rc=0
		php_run "$version" php vendor/bin/phpunit >"$LOG_DIR/$version.log" 2>&1 || rc=1
		php_run "$version" php vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
			>>"$LOG_DIR/$version.log" 2>&1 || rc=1
		exit "$rc"
	} &
	pids+=("$!")
done

overall=0
for i in "${!VERSIONS[@]}"; do
	version="${VERSIONS[$i]}"
	if wait "${pids[$i]}"; then
		echo "-- PHP $version: PASS"
	else
		overall=1
		echo "-- PHP $version: FAIL" >&2
		cat "$LOG_DIR/$version.log" >&2
	fi
done

echo "== PHPCS (WordPress standards, single run — testVersion 8.0-) =="
php_run 8.5 php vendor/bin/phpcs || overall=1

if [ "$overall" -eq 0 ]; then
	echo "php-matrix: OK — PHP ${VERSIONS[*]} all clean."
else
	echo "php-matrix: FAIL — see findings above." >&2
fi

exit "$overall"
