#!/usr/bin/env bash
#
# Mirror a released tag to the WordPress.org SVN repository: trunk/ gets the
# exact contents of the installable ZIP (bin/package.sh), assets/ gets the
# icon/banner/screenshots from .wordpress-org/, and a new tags/VERSION is cut
# from trunk — all in one commit, per the WP.org SVN guide
# (developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion).
#
# Usage: bin/svn-deploy.sh <tag> [zip_path]
#   <tag>       e.g. v1.2.0
#   [zip_path]  a pre-built ZIP (bin/package.sh's output) to use as-is. When
#               omitted, this script builds one itself via `bin/package.sh
#               <tag>` — fine for a local/manual run, but CI (svn-deploy.yml)
#               always passes one: that job runs with WordPress.org
#               credentials in its environment, and building the ZIP means
#               running <tag>'s own composer/npm scripts, which would hand
#               those credentials to whatever code that tag happens to
#               contain. Building instead happens in release.yml, which
#               carries no SVN secrets, and its ZIP is passed here as a
#               build artifact.
# Env:   SVN_USERNAME, SVN_PASSWORD (a WordPress.org account with commit
#        access to the plugin)
#
set -euo pipefail

SLUG='wynko'
SVN_URL="https://plugins.svn.wordpress.org/$SLUG"

fail() {
	printf 'svn-deploy: %s\n' "$1" >&2
	exit 1
}

tag="${1:-}"
[ -n "$tag" ] || fail 'usage: bin/svn-deploy.sh <tag> [zip_path]'
version="${tag#v}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "not a version tag: $tag"
zip_path="${2:-}"

[ -n "${SVN_USERNAME:-}" ] || fail 'SVN_USERNAME is not set'
[ -n "${SVN_PASSWORD:-}" ] || fail 'SVN_PASSWORD is not set'

ROOT="$(git rev-parse --show-toplevel)"
STAGING="$(mktemp -d)"

cleanup() {
	rm -rf "$STAGING"
}
trap cleanup EXIT

svn_cmd() {
	svn --username "$SVN_USERNAME" --password "$SVN_PASSWORD" \
		--non-interactive --no-auth-cache "$@"
}

if [ -z "$zip_path" ]; then
	printf '==> building the installable ZIP for %s\n' "$tag"
	zip_path="$("$ROOT/bin/package.sh" "$tag" | tail -n1)"
fi
[ -f "$zip_path" ] || fail "zip not found: $zip_path"
unzip -q "$zip_path" -d "$STAGING/zip"
trunk_src="$STAGING/zip/$SLUG"
[ -d "$trunk_src" ] || fail "unexpected ZIP layout: $trunk_src not found"

printf '==> checking out the SVN working copy\n'
wc="$STAGING/svn"
svn_cmd checkout --depth=immediates "$SVN_URL" "$wc"
svn_cmd update --set-depth infinity "$wc/trunk" "$wc/assets"

sync_dir() {
	local src="$1" dest="$2"
	rsync -a --delete --exclude '.svn' "$src"/ "$dest"/
	svn_cmd add --force --quiet "$dest"
	svn status "$dest" | sed -n 's/^!\s*//p' | while IFS= read -r missing; do
		svn_cmd rm --quiet "$missing"
	done
}

printf '==> syncing trunk/\n'
sync_dir "$trunk_src" "$wc/trunk"

printf '==> syncing assets/\n'
sync_dir "$ROOT/.wordpress-org" "$wc/assets"

tag_dir="$wc/tags/$version"
if svn_cmd info "$SVN_URL/tags/$version" >/dev/null 2>&1; then
	printf '==> tags/%s already exists on the server, skipping\n' "$version"
else
	printf '==> cutting tags/%s from trunk\n' "$version"
	svn_cmd cp "$wc/trunk" "$tag_dir"
fi

if svn status "$wc" | grep -q .; then
	printf '==> committing\n'
	svn_cmd commit -m "Release $version" "$wc"
else
	printf '==> nothing changed, nothing to commit\n'
fi
