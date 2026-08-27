#!/usr/bin/env bash
#
# Wynko WordPress.org readiness check — catches known submission blockers in
# readme.txt before bin/release.sh cuts a release. Not exhaustive: it is a
# guard against known mistakes, not a substitute for the official Plugin
# Check plugin (see CONTRIBUTING.md's Releasing section).
#
# Usage: bin/wp-org-check.sh
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

readme='readme.txt'
plugin_file='wynko.php'

field() {
	sed -n "s/^$1:[[:space:]]*//p" "$readme" | head -n1
}

failures=()

readme_title="$(sed -n 's/^===[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*===$/\1/p' "$readme" | head -n1)"
plugin_name="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Plugin Name:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$plugin_file")"
if [ -z "$readme_title" ]; then
	failures+=("=== Title === is missing from $readme.")
elif [ -z "$plugin_name" ]; then
	failures+=("Plugin Name: is missing from $plugin_file's header.")
elif [ "$readme_title" != "$plugin_name" ]; then
	failures+=("Plugin Name: $plugin_file's header ($plugin_name) does not match $readme's === Title === ($readme_title) — WordPress.org requires these to match.")
fi

contributors="$(field 'Contributors')"
if [ -z "$contributors" ] || [ "$contributors" = 'TODO' ]; then
	failures+=("Contributors: is empty or a placeholder — set real wordpress.org usernames.")
fi

tags="$(field 'Tags')"
tag_count="$(printf '%s' "$tags" | tr ',' '\n' | sed '/^[[:space:]]*$/d' | wc -l | tr -d ' ')"
if [ "$tag_count" -gt 5 ]; then
	failures+=("Tags: lists $tag_count tags — WordPress.org allows at most 5.")
fi

stable_tag="$(field 'Stable tag')"
plugin_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$plugin_file")"
if [ -z "$stable_tag" ]; then
	failures+=("Stable tag: is missing from $readme.")
elif [ "$stable_tag" != "$plugin_version" ]; then
	failures+=("Stable tag: ($stable_tag) does not match $plugin_file's Version: ($plugin_version).")
fi

license="$(field 'License')"
license_uri="$(field 'License URI')"
if [ -z "$license" ] || [ -z "$license_uri" ]; then
	failures+=("License:/License URI: is missing from $readme.")
fi

header_domain="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Text Domain:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$plugin_file")"
phpcs_domain="$(sed -n 's/.*<element value="\([^"]*\)"\/>.*/\1/p' phpcs.xml.dist | head -n1)"
if [ -z "$header_domain" ]; then
	failures+=("Text Domain: is missing from $plugin_file's header.")
elif [ -z "$phpcs_domain" ]; then
	failures+=("Text Domain: phpcs.xml.dist has no WordPress.WP.I18n text_domain configured to check it against.")
elif [ "$header_domain" != "$phpcs_domain" ]; then
	failures+=("Text Domain: $plugin_file's header ($header_domain) does not match phpcs.xml.dist's configured text_domain ($phpcs_domain) — the i18n sniff is checking call sites against the wrong value.")
fi

if [ "${#failures[@]}" -gt 0 ]; then
	echo "wp-org-check: FAIL" >&2
	for f in "${failures[@]}"; do
		printf '  - %s\n' "$f" >&2
	done
	exit 1
fi

echo "wp-org-check: OK — Plugin Name matches readme title, Contributors set, $tag_count tag(s), Stable tag matches $plugin_version, license present, Text Domain consistent with phpcs.xml.dist."
