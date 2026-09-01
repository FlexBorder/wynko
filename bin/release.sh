#!/usr/bin/env bash
#
# Wynko release script — the only sanctioned way to cut a release. Bumps the
# version, drafts a changelog entry for approval, commits and tags on
# `origin` (private, full history), then builds a filtered snapshot (see
# .publishignore) and applies it as a single new commit on `public` (public
# GitHub repo, one commit per release, signed as roy-dg). See
# CONTRIBUTING.md's Releasing section and
# docs/superpowers/specs/2026-08-21-ci-cd-pipeline-design.md.
#
# Usage: bin/release.sh
# Requires: RELEASE_GPG_KEY_ID, RELEASE_SSH_HOST in the environment.
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

SLUG='wynko'
PLUGIN_FILE="$SLUG.php"
README='readme.txt'
PUBLIC_CHECKOUT='.release/public-checkout'

fail() {
	printf 'release: %s\n' "$1" >&2
	exit 1
}

confirm() {
	local prompt="$1" reply
	printf '%s [y/N] ' "$prompt"
	read -r reply
	[ "$reply" = 'y' ]
}

# --- Preconditions -----------------------------------------------------

: "${RELEASE_GPG_KEY_ID:?release: RELEASE_GPG_KEY_ID is not set — see CONTRIBUTING.md's Remotes & release identity section.}"
: "${RELEASE_SSH_HOST:?release: RELEASE_SSH_HOST is not set — see CONTRIBUTING.md's Remotes & release identity section.}"

[ -z "$(git status --porcelain)" ] || fail "working tree is dirty — commit or stash first."
[ "$(git branch --show-current)" = 'main' ] || fail "switch to main first: git switch main"
git remote get-url origin >/dev/null 2>&1 || fail "no 'origin' remote configured."
git remote get-url public >/dev/null 2>&1 || fail "no 'public' remote configured."

bin/wp-org-check.sh || fail "WP.org readiness check failed — fix the issues above first."

# --- E2E gate ---------------------------------------------------------
#
# The signup-form + caching e2e suite (tests/e2e/, Playwright + wp-env)
# must finish without failures before a release. It is not on the
# per-commit or per-PR gate — it needs the live Laposta test account and
# real network egress, which fork PRs and the offline pre-commit hook
# can't have — but a release is cut from this machine, by a maintainer who
# does have those, so this is where it belongs. A clean skip (a caching
# plugin that won't cache headlessly under wp-env) is acceptable; an
# actual failure blocks the release.

[ -n "${WYNKO_TEST_API_KEY:-}" ] || fail "WYNKO_TEST_API_KEY is not set — the e2e release gate needs the live Laposta test key (see the Releasing section in CONTRIBUTING.md)."
[ -n "${WYNKO_TEST_LIST_ID:-}" ] || fail "WYNKO_TEST_LIST_ID is not set — the e2e release gate needs the Laposta test list id."

echo "release: running the e2e suite (signup forms + caching) — several minutes…"
# `@wordpress/env` (via npx) stalls on hosts where Node's happy-eyeballs
# picks an unreachable IPv6 route first; disable the autoselection for the
# duration of the gate. Harmless where it isn't needed.
export NODE_OPTIONS="${NODE_OPTIONS:+$NODE_OPTIONS }--no-network-family-autoselection"
npx @wordpress/env start >/dev/null 2>&1 || fail "could not start wp-env for the e2e gate."
# Just the browser binary — not `test:e2e:install`'s `--with-deps`, which
# needs root and only makes sense on a CI runner. Idempotent; a no-op when
# Chromium is already present.
npx playwright install chromium >/dev/null 2>&1 || fail "could not install the Playwright Chromium build for the e2e gate."
npm run test:e2e || fail "the e2e suite did not pass (test names above) — fix it before releasing."

# --- Compute the suggested version bump ---------------------------------

current_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$PLUGIN_FILE")"
[ -n "$current_version" ] || fail "no Version header in $PLUGIN_FILE"

last_tag="$(git tag --list 'v*' --sort=-v:refname | head -n1)"

draft_file="$(mktemp)"
trap 'rm -f "$draft_file"' EXIT

if [ -z "$last_tag" ]; then
	# Bootstrap case: readme.txt already documents the current version's
	# changelog by hand (e.g. "Initial release."). Dumping the entire commit
	# history as a draft here would be useless noise, so this release keeps
	# the current version and an empty draft — edit it if you want an entry.
	printf 'release: no prior release tag — this will be the first release (v%s), version unchanged.\n' "$current_version"
	suggested_version="$current_version"
	version="$current_version"
	: >"$draft_file"
else
	printf 'release: commits since %s:\n' "$last_tag"
	commit_hashes="$(git log --pretty=format:'%H' "$last_tag..HEAD")"
	[ -n "$commit_hashes" ] || fail "no commits since $last_tag — nothing to release."

	bump='patch'
	declare -a feat_lines=() fix_lines=() security_lines=() changed_lines=() docs_lines=() removed_lines=()

	while IFS= read -r hash; do
		[ -n "$hash" ] || continue
		subject="$(git log -1 --pretty=%s "$hash")"
		body="$(git log -1 --pretty=%b "$hash")"
		bang=''

		if [[ "$subject" =~ ^([a-z]+)(\([a-z0-9./-]+\))?(!)?:\ (.+)$ ]]; then
			type="${BASH_REMATCH[1]}"
			bang="${BASH_REMATCH[3]}"
			summary="${BASH_REMATCH[4]}"
			summary="$(printf '%s' "$summary" | sed 's/^./\U&/')"
		else
			type='chore'
			summary="$subject"
		fi

		if [ -n "$bang" ] || printf '%s\n' "$body" | grep -q '^BREAKING CHANGE:'; then
			bump='major'
		elif [ "$type" = 'feat' ] && [ "$bump" != 'major' ]; then
			bump='minor'
		fi

		case "$type" in
			feat) feat_lines+=("* Added: $summary") ;;
			fix) fix_lines+=("* Fixed: $summary") ;;
			security) security_lines+=("* Security: $summary") ;;
			perf | refactor) changed_lines+=("* Changed: $summary") ;;
			docs) docs_lines+=("* Documentation: $summary") ;;
			revert) removed_lines+=("* Removed: $summary") ;;
			*) : ;; # chore/ci/build/test — internal, left out of the changelog
		esac
	done <<<"$commit_hashes"

	IFS='.' read -r major minor patch <<<"$current_version"
	case "$bump" in
		major) suggested_version="$((major + 1)).0.0" ;;
		minor) suggested_version="$major.$((minor + 1)).0" ;;
		patch) suggested_version="$major.$minor.$((patch + 1))" ;;
	esac

	printf '\nrelease: suggested version: %s (bump: %s)\n' "$suggested_version" "$bump"
	printf 'release: version to release [%s]: ' "$suggested_version"
	read -r version_input
	version="${version_input:-$suggested_version}"
	[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "not a valid version: $version"

	{
		printf '= %s =\n' "$version"
		for line in "${feat_lines[@]-}" "${fix_lines[@]-}" "${security_lines[@]-}" \
			"${changed_lines[@]-}" "${docs_lines[@]-}" "${removed_lines[@]-}"; do
			[ -n "$line" ] && printf '%s\n' "$line"
		done
	} >"$draft_file"
fi

echo
echo "release: draft changelog entry —"
cat "$draft_file"
echo

if confirm 'release: edit the draft before continuing?'; then
	"${EDITOR:-vi}" "$draft_file"
	echo "release: revised draft —"
	cat "$draft_file"
fi

confirm "release: write version $version and this changelog entry, then commit and tag?" ||
	fail "cancelled."

changelog_entry="$(cat "$draft_file")"

# --- Bump the version and write the changelog ---------------------------

sed -i "s/^\([[:space:]]*\*[[:space:]]*Version:[[:space:]]*\).*/\1$version/" "$PLUGIN_FILE"
sed -i "s/^define( 'WYNKO_VERSION', '.*' );/define( 'WYNKO_VERSION', '$version' );/" "$PLUGIN_FILE"
sed -i "s/^Stable tag:.*/Stable tag:        $version/" "$README"

if [ -n "$changelog_entry" ]; then
	# entry_file is read via getline rather than passed as an awk -v string,
	# so backslashes in commit summaries (e.g. "Support\Sbom") can't be
	# misread as awk escape sequences.
	awk -v entry_file="$draft_file" '
		/^== Changelog ==$/ {
			print; print "";
			while ((getline line < entry_file) > 0) print line;
			next
		}
		{ print }
	' "$README" >"$README.new" && mv "$README.new" "$README"
fi

git add "$PLUGIN_FILE" "$README"
if git diff --cached --quiet; then
	# First release with no version bump and no changelog entry: nothing to
	# commit, but the tag still marks this HEAD as the release point.
	printf 'release: no version/changelog change — tagging the current commit as v%s.\n' "$version"
else
	git commit -q -m "chore(release): $version"
fi
git tag -a "v$version" -m "Release $version"

git push origin main
git push origin "v$version"

printf 'release: %s committed and tagged on origin.\n' "$version"

# --- Build the filtered public snapshot ----------------------------------

staging="$(mktemp -d)"
trap 'rm -rf "$staging" "$draft_file"' EXIT

git archive --format=tar "v$version" | tar -x -C "$staging"

excluded=()
while IFS= read -r line; do
	line="${line%%#*}"
	line="${line#"${line%%[![:space:]]*}"}"
	line="${line%"${line##*[![:space:]]}"}"
	[ -n "$line" ] && excluded+=("${line#/}")
done <"$ROOT/.publishignore"
for path in "${excluded[@]}"; do
	rm -rf "${staging:?}/$path"
done

# --- Apply the snapshot as a single commit on the public checkout --------

public_url="$(git remote get-url public)"
if [[ "$public_url" != *"$RELEASE_SSH_HOST"* ]]; then
	printf 'release: warning — public remote (%s) does not reference %s.\n' "$public_url" "$RELEASE_SSH_HOST" >&2
fi

if [ ! -d "$PUBLIC_CHECKOUT/.git" ]; then
	mkdir -p "$PUBLIC_CHECKOUT"
	git -C "$PUBLIC_CHECKOUT" init -q -b main
	git -C "$PUBLIC_CHECKOUT" remote add origin "$public_url"
	git -C "$PUBLIC_CHECKOUT" fetch -q origin main 2>/dev/null &&
		git -C "$PUBLIC_CHECKOUT" reset -q --hard origin/main || true
fi

# Set once per run (not per-command -c overrides) so every command in this
# checkout — including `tag`, which needs a tagger identity too — uses the
# roy-dg identity, not whatever ambient git config this machine has.
git -C "$PUBLIC_CHECKOUT" config user.name 'roy-dg'
git -C "$PUBLIC_CHECKOUT" config user.email '3306895+roy-dg@users.noreply.github.com'
git -C "$PUBLIC_CHECKOUT" config user.signingkey "$RELEASE_GPG_KEY_ID"
git -C "$PUBLIC_CHECKOUT" config commit.gpgsign true
git -C "$PUBLIC_CHECKOUT" config tag.gpgsign true

rsync -a --delete --exclude='.git' "$staging/" "$PUBLIC_CHECKOUT/"

git -C "$PUBLIC_CHECKOUT" add -A
if git -C "$PUBLIC_CHECKOUT" diff --cached --quiet; then
	fail "the public snapshot for $version is identical to the current public main — nothing to release."
fi

commit_args=(commit -q -m "Release $version")
[ -n "$changelog_entry" ] && commit_args+=(-m "$changelog_entry")
git -C "$PUBLIC_CHECKOUT" "${commit_args[@]}"
git -C "$PUBLIC_CHECKOUT" tag -a "v$version" -m "Release $version"

git -C "$PUBLIC_CHECKOUT" push origin main
git -C "$PUBLIC_CHECKOUT" push origin "v$version"

printf 'release: %s published to public.\n' "$version"
printf 'release: the public repo'"'"'s .github/workflows/release.yml now builds the ZIP and creates the GitHub Release automatically from this push.\n'
