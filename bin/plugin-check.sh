#!/usr/bin/env bash
#
# Wynko Plugin Check — runs the official WordPress.org Plugin Check tool, all
# categories, strict (every WARNING fails the build exactly like an ERROR —
# see .github/workflows/ci.yml's plugin-check job for why: this is the
# category WordPress.org's own review draws from, and it was the tool that
# caught TD-056's naming rejection). Scoped the same way the built release
# ZIP is: paths .distignore lists, plus git-ignored local build artifacts
# (dist/, .release/) that a real checkout never has.
#
# Requires `npx @wordpress/env start` already running. Fails closed — rather
# than skipping — if it isn't, since an unrunnable check is not a passed one.
#
# `wp plugin check` (and the `npx @wordpress/env run` wrapper around it) both
# exit 0 even when findings are reported — confirmed empirically: a run
# scoped wide enough to hit real findings still returned exit 0. Pass/fail
# here is therefore judged from the tool's own output text, not its exit
# code: WP-CLI prints the literal line "Success: Checks complete. No errors
# found." only when nothing was found; anything else means something was.
#
# Usage: bin/plugin-check.sh
#
set -uo pipefail

cd "$(git rev-parse --show-toplevel)"

if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -q -- '-cli-1$'; then
	echo "plugin-check: wp-env isn't running. Start it first:" >&2
	echo "  npx @wordpress/env start" >&2
	exit 1
fi

PLUGIN_SLUG='wynko-for-laposta'

if ! npx @wordpress/env run cli wp plugin list --format=csv 2>/dev/null | grep -q "^$PLUGIN_SLUG,"; then
	echo "plugin-check: the '$PLUGIN_SLUG' plugin isn't active in wp-env." >&2
	exit 1
fi

# Not declared in .wp-env.json's "plugins" list (that would ship it into
# every environment, dev included, for a tool only this script needs) — so a
# recreated wp-env instance starts without it. Install it on demand rather
# than documenting a manual step someone has to remember.
if ! npx @wordpress/env run cli wp plugin list --format=csv 2>/dev/null | grep -q '^plugin-check,active,'; then
	echo "plugin-check: installing the Plugin Check companion plugin into wp-env…"
	npx @wordpress/env run cli wp plugin install plugin-check --activate || {
		echo "plugin-check: failed to install the Plugin Check plugin into wp-env." >&2
		exit 1
	}
fi

dirs=()
files=()
while IFS= read -r line; do
	line="${line%%#*}"
	line="${line#"${line%%[![:space:]]*}"}"
	line="${line%"${line##*[![:space:]]}"}"
	[ -z "$line" ] && continue
	path="${line#/}"
	if [ -d "$path" ]; then
		dirs+=("$path")
	else
		files+=("$path")
	fi
done <.distignore
[ "${#dirs[@]}" -gt 0 ] || [ "${#files[@]}" -gt 0 ] || {
	echo "plugin-check: .distignore listed no paths." >&2
	exit 1
}

# Local-only, git-ignored artifacts a real checkout (CI, bin/package.sh) never
# has — exclude them too so this run scopes the same way CI's plugin-check
# job does. (vendor/, node_modules/, build/ are deliberately NOT here: CI
# generates those too before running Plugin Check, so they're real scope.)
# artifacts/ is where the e2e suite writes its Playwright output and storage
# state, including hidden files like .last-run.json.
dirs+=("dist" ".release" "artifacts")
files+=(".release.env")

dirs_csv="$(IFS=,; echo "${dirs[*]}")"
files_csv="$(IFS=,; echo "${files[*]}")"

echo "== Wynko Plugin Check (all categories, strict) =="
output="$(npx @wordpress/env run cli wp plugin check "$PLUGIN_SLUG" --format=strict-table \
	--exclude-directories="$dirs_csv" --exclude-files="$files_csv" 2>&1)"

echo "$output"

if ! printf '%s' "$output" | grep -q 'Success: Checks complete. No errors found.'; then
	echo "plugin-check: FAIL — see findings above." >&2
	exit 1
fi

echo "plugin-check: OK"
