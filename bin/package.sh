#!/usr/bin/env bash
#
# Build an installable plugin ZIP from a committed ref (default: main).
#
# The archive is assembled in a temporary directory from `git archive`, so the
# working tree — dirty or not — never reaches the artifact. Everything the
# plugin needs at runtime but does not track (vendor/autoload.php, build/) is
# generated there, then the paths listed in .distignore are stripped.
#
# Usage: bin/package.sh [ref]
# Output: dist/wynko-<version>.zip

set -euo pipefail

SLUG='wynko'
TEXT_DOMAIN='wynko-for-laposta'
REF="${1:-main}"

ROOT="$(git rev-parse --show-toplevel)"
STAGING="$(mktemp -d)"
PLUGIN="$STAGING/$SLUG"

cleanup() {
	rm -rf "$STAGING"
}
trap cleanup EXIT

fail() {
	printf 'package: %s\n' "$1" >&2
	exit 1
}

git -C "$ROOT" rev-parse --verify --quiet "$REF^{commit}" >/dev/null ||
	fail "unknown ref: $REF"

printf 'Packaging %s from %s\n' "$SLUG" "$REF"

mkdir -p "$PLUGIN"
git -C "$ROOT" archive --format=tar "$REF" | tar -x -C "$PLUGIN"

printf '==> composer install --no-dev\n'
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
	-v "$PLUGIN":/app -w /app composer:2 \
	install --no-dev --no-interaction --optimize-autoloader

printf '==> npm ci && npm run build\n'
npm --prefix "$PLUGIN" ci --no-audit --no-fund
npm --prefix "$PLUGIN" run build

printf '==> stripping .distignore paths\n'
excluded=()
while IFS= read -r line; do
	line="${line%%#*}"
	line="${line#"${line%%[![:space:]]*}"}"
	line="${line%"${line##*[![:space:]]}"}"
	[ -n "$line" ] && excluded+=("${line#/}")
done <"$PLUGIN/.distignore"
[ "${#excluded[@]}" -gt 0 ] || fail '.distignore listed no paths'
for path in "${excluded[@]}"; do
	rm -rf "${PLUGIN:?}/$path"
done

printf '==> verifying the archive is installable\n'
required=(
	"$SLUG.php"
	vendor/autoload.php
	build/block/block.json
	build/block-form/block.json
	build/admin/forms.js
	build/admin/forms.asset.php
	build/frontend/form.js
	build/frontend/form.asset.php
	uninstall.php
	languages/$TEXT_DOMAIN.pot
)
for path in "${required[@]}"; do
	[ -e "$PLUGIN/$path" ] || fail "missing from the archive: $path"
done
for path in "${excluded[@]}"; do
	[ ! -e "$PLUGIN/$path" ] || fail "should not be in the archive: $path"
done

version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$PLUGIN/$SLUG.php")"
[ -n "$version" ] || fail "no Version header in $SLUG.php"

zip_path="$ROOT/dist/$SLUG-$version.zip"
mkdir -p "$ROOT/dist"
rm -f "$zip_path"
( cd "$STAGING" && zip -rq "$zip_path" "$SLUG" -x '*.DS_Store' )

printf '\n%s\n' "$zip_path"
