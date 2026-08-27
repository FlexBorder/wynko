#!/usr/bin/env bash
#
# Wynko PHP-version matrix — mirrors CI's `build` job across PHP
# 8.0/8.1/8.2/8.3/8.4/8.5: for each version, a fresh `composer install`
# under that version's own php:<version>-cli Docker image (composer itself
# installed there too, so dependency resolution sees that PHP's platform,
# not whatever PHP composer.phar happens to run under), then PHPUnit, PHPCS
# (coding standards), and PHPStan under the same interpreter.
#
# Used only by bin/merge-to-main.sh — full reinstall per version is slow
# (network + Docker), so it doesn't run on every commit. bin/unit-tests.sh,
# bin/coding-standards.sh, and bin/static-analysis.sh cover a single
# version there instead.
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

run() {
	local version="$1"
	shift
	docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
		-v "$PWD":/app -w /app "php:${version}-cli" "$@"
}

overall=0

for version in "${VERSIONS[@]}"; do
	echo "== PHP $version: composer install =="
	if ! run "$version" sh -c '
		php -r "copy(\"https://getcomposer.org/installer\", \"/tmp/composer-setup.php\");" &&
		php /tmp/composer-setup.php --install-dir=/tmp --filename=composer.phar --quiet &&
		php /tmp/composer.phar install --no-interaction --no-progress
	'; then
		echo "php-matrix: composer install failed under PHP $version." >&2
		overall=1
		continue
	fi

	echo "== PHP $version: unit tests (PHPUnit) =="
	run "$version" php vendor/bin/phpunit || overall=1

	echo "== PHP $version: coding standards (PHPCS) =="
	run "$version" php vendor/bin/phpcs || overall=1

	echo "== PHP $version: static analysis (PHPStan) =="
	run "$version" php vendor/bin/phpstan analyse --memory-limit=1G || overall=1
done

if [ "$overall" -eq 0 ]; then
	echo "php-matrix: OK — PHP ${VERSIONS[*]} all clean."
else
	echo "php-matrix: FAIL — see findings above." >&2
fi

exit "$overall"
