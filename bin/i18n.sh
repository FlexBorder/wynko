#!/usr/bin/env bash
#
# Wynko translation catalogue regeneration.
#
# There is no local PHP and no local WP-CLI, so this runs WP-CLI inside the
# wp-env container, which already mounts the plugin (see .wp-env.json). Start it
# first with `npx @wordpress/env start`.
#
# Three stages, in order:
#   1. make-pot   — re-extract every translatable string into the template.
#   2. update-po  — merge the template into each translation, keeping existing
#                   msgstr values and marking changed ones fuzzy.
#   3. make-mo / make-json — compile what WordPress actually loads at runtime.
#
# Between 2 and 3 a human fills in the new msgstr values and clears the fuzzy
# flags. Running the whole script twice is safe: stage 2 never discards work.
#
# src/ is scanned rather than build/, so JS strings are extracted from source
# and never twice. block.json is picked up automatically, which is what puts the
# block's title and description in the catalogue.
#
# Two things `wp-env run` doesn't do for free, worked around below:
#   - Its default working directory is the WordPress root, not the mounted
#     plugin, so every command is pinned to the plugin's --env-cwd or it
#     silently reads and writes the wrong "languages/".
#   - The cli container's default 128M PHP memory_limit is too small for
#     make-pot's JS parser over this many source files; it's raised to 512M
#     for the duration of each call only, not globally.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

DOMAIN='wynko-for-laposta'
POT="languages/$DOMAIN.pot"
EXCLUDE='node_modules,vendor,build,tests,bin,docs,sbom,.git'
PLUGIN_DIR="wp-content/plugins/$DOMAIN"

wp() {
	local quoted
	quoted="$(printf '%q ' "$@")"
	npx --yes @wordpress/env run --env-cwd="$PLUGIN_DIR" cli \
		bash -c "php -d memory_limit=512M \$(which wp) $quoted"
}

echo "== Wynko i18n: extracting $POT =="
wp i18n make-pot . "$POT" \
	--domain="$DOMAIN" \
	--exclude="$EXCLUDE" \
	--skip-audit

echo "== Wynko i18n: merging into translations =="
wp i18n update-po "$POT" languages

echo "== Wynko i18n: compiling .mo =="
wp i18n make-mo languages

echo "== Wynko i18n: regenerating editor-script JSON =="
wp i18n make-json languages --no-purge --pretty-print

# make-json names its output after the md5 of the *source* path it read from
# the .po — e.g. src/block/edit.js. WordPress never looks for that name: it
# resolves each block's editor script by handle, so the generated file is
# renamed to the handle form, which is the one load_script_textdomain()
# actually finds. Without this the catalogue regenerates into files nothing
# reads.
#
# One block.json = one editor-script source = one handle, following the same
# convention core uses for a bare `"editorScript": "file:./index.js"`: the
# block's name with the namespace slash turned into a dash, plus
# "-editor-script". Building the map from src/*/block.json means a new block
# picks up its rename automatically instead of silently losing its JSON file
# to the next block's `mv -f`.
declare -A source_handles
for block_json in src/*/block.json; do
	block_dir="$(dirname "$block_json")"
	name="$(grep -o '"name"[[:space:]]*:[[:space:]]*"[^"]*"' "$block_json" | head -1 | sed -E 's/.*"([^"]+)"$/\1/')"
	source_handles["$block_dir/edit.js"]="${name/\//-}-editor-script"
done

# Only the freshly generated md5-named files need this rename — an
# already-handle-named file from a previous run matches the same glob and
# would otherwise get renamed a second time, appending its handle to itself.
for json in languages/"$DOMAIN"-*-????????????????????????????????.json; do
	[ -e "$json" ] || continue
	base="$(basename "$json" .json)"
	locale="${base#"$DOMAIN"-}"
	locale="${locale%-*}"
	source="$(grep -o '"source"[[:space:]]*:[[:space:]]*"[^"]*"' "$json" | head -1 | sed -E 's/.*"([^"]+)"$/\1/' | sed 's/\\\//\//g')"
	handle="${source_handles[$source]:-}"
	if [ -z "$handle" ]; then
		echo "i18n: $json has no known block handle for source '$source'." >&2
		echo "i18n: add its block.json under src/*/block.json, or map it by hand." >&2
		exit 1
	fi
	mv -f "$json" "languages/$DOMAIN-$locale-$handle.json"
	echo "i18n: renamed $(basename "$json") -> $DOMAIN-$locale-$handle.json"
done

echo "i18n: OK — review languages/*.po for new and fuzzy entries."
