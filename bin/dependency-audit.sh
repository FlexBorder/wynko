#!/usr/bin/env bash
#
# Wynko dependency audit — reports outdated packages and known security
# advisories across both ecosystems. Run before every release (see
# CONTRIBUTING.md).
#
# This reports; it does not gate. A dependency going stale upstream should not
# block an unrelated commit, but it must not go unnoticed at release time
# either. Anything found here is either upgraded or written up as a deferral.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer_run() {
	docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
		-v "$PWD":/app -w /app composer:2 "$@"
}

echo "== Composer: outdated direct dependencies =="
composer_run outdated --direct || true

echo
echo "== Composer: security advisories =="
composer_run audit || true

echo
echo "== npm: outdated dependencies =="
npm outdated || true

echo
echo "== npm: security advisories =="
npm audit || true

echo
echo "dependency-audit: review the findings above — upgrade, or record the"
echo "deferral."
