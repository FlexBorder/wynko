#!/usr/bin/env bash
#
# Wynko SBOM generation — writes CycloneDX 1.6 documents listing the
# dependencies the plugin itself uses: the packages that end up in the
# distributed archive, and their transitive runtime dependencies.
#
# Dev tooling (phpunit, phpcs, phpstan, wp-scripts…) is deliberately omitted.
# It is used to build and verify the plugin, not by the plugin, and listing it
# would tell a downstream scanner that the shipped artifact contains it.
#
# Today both documents are empty of components: this plugin requires only PHP
# itself, and every JavaScript import is externalised to a WordPress script
# handle rather than bundled. That is the correct answer, and the point is that
# the first real dependency cannot be added without showing up here — see
# bin/sbom-check.sh.
#
# Both generators run with --output-reproducible and a pinned root version, so
# regenerating without a dependency change produces no diff. That is what lets
# bin/sbom-check.sh treat any diff as "the SBOM is stale".
#
# The generators are deliberately NOT project dependencies: the Composer plugin
# needs PHP 8.1+ while this plugin supports 8.0, and a generator listed in its
# own output is noise. Both are pinned here instead.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# Pinned to exact versions, not ranges: a floating range would pull a new
# generator on some unrelated day, change the output, and turn bin/sbom-check.sh
# red on a commit that touched no dependency. Bumping these is a deliberate act
# whose SBOM diff lands in the same commit. Their own dependency trees stay
# unpinned, so reproducibility stops at the generators themselves.
CDX_COMPOSER_VERSION='6.2.0'
CDX_NPM_VERSION='4.2.1'
SPEC_VERSION='1.6'

# The plugin header is the single source of truth for the version; Composer
# would otherwise guess it from the current branch name.
plugin_version="$(sed -n 's/^ \* Version: *//p' wynko.php | tr -d '[:space:]')"

if [ -z "$plugin_version" ]; then
	echo "sbom-generate: could not read Version from wynko.php." >&2
	exit 1
fi

mkdir -p sbom

echo "== Wynko SBOM: Composer runtime (root version $plugin_version) =="
if [ ! -f composer.lock ]; then
	echo "sbom-generate: composer.lock not found — run 'composer install' first." >&2
	exit 1
fi

# The generator reads the *installed* tree (vendor/composer/installed.json), not
# composer.lock, so a stale vendor/ silently produces a wrong SBOM. Installing
# from the lock first makes the lock the effective source of truth. This is a
# no-op when the two already agree.
docker run --rm -u "$(id -u):$(id -g)" \
	-e COMPOSER_HOME=/tmp/composer \
	-e COMPOSER_ROOT_VERSION="$plugin_version" \
	-v "$PWD":/app -w /app composer:2 sh -c "
		composer install --no-interaction --no-progress --quiet &&
		composer global config --no-plugins allow-plugins.cyclonedx/cyclonedx-php-composer true --quiet &&
		composer global require cyclonedx/cyclonedx-php-composer:$CDX_COMPOSER_VERSION --no-interaction --quiet &&
		composer CycloneDX:make-sbom \
			--output-format=JSON \
			--output-file=sbom/composer.cdx.json \
			--output-reproducible \
			--spec-version=$SPEC_VERSION \
			--omit=dev \
			composer.json
	"

echo "== Wynko SBOM: npm runtime =="
if [ ! -f package-lock.json ]; then
	echo "sbom-generate: package-lock.json not found — run 'npm install' first." >&2
	exit 1
fi

# `--` terminates the variadic --omit list, which would otherwise swallow the
# package.json argument.
npx --yes "@cyclonedx/cyclonedx-npm@$CDX_NPM_VERSION" \
	--output-format JSON \
	--output-file sbom/npm.cdx.json \
	--output-reproducible \
	--spec-version "$SPEC_VERSION" \
	--omit dev \
	-- ./package.json

echo "sbom-generate: OK — sbom/composer.cdx.json, sbom/npm.cdx.json"
