#!/usr/bin/env bash
#
# Wynko staged-file lint — the fast slice of the coding-standards gate,
# scoped to the files this commit actually touches. Runs the same rulesets
# as bin/coding-standards.sh / bin/security-scan.sh / bin/js-lint.sh /
# bin/style-lint.sh (phpcs.xml.dist, phpcs-security.xml.dist,
# @wordpress/eslint-plugin, @wordpress/stylelint-config) — only the target
# is narrower.
#
# The full-tree versions of these checks, plus PHPStan, Semgrep, and Plugin
# Check, run once per branch in bin/gate.sh (the first step of
# bin/merge-to-main.sh) rather than on every commit. That means a violation
# in an already-committed file, or cross-file breakage, surfaces at merge
# rather than at the triggering commit — a deliberate trade, see
# TECHNICAL_DEBT.md TD-073.
#
# Uses local PHP when available, otherwise the pinned Docker image.
#
# Usage: bin/lint-staged.sh
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

run_php() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif command -v docker >/dev/null 2>&1; then
		docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli php "$@"
	else
		echo "lint-staged: requires local PHP or Docker." >&2
		exit 1
	fi
}

mapfile -t staged < <(git diff --cached --name-only --diff-filter=ACM)

php_files=()
asset_files=()
for f in "${staged[@]}"; do
	case "$f" in
		*.php) [ -f "$f" ] && php_files+=("$f") ;;
		src/*.js|src/*.jsx|src/*.ts|src/*.tsx|src/*.scss|src/*.css)
			[ -f "$f" ] && asset_files+=("$f") ;;
	esac
done

status=0

if [ "${#php_files[@]}" -gt 0 ]; then
	echo "== Wynko staged PHP: coding standards (phpcs.xml.dist) =="
	run_php vendor/bin/phpcs "${php_files[@]}" || status=1

	echo "== Wynko staged PHP: security ruleset (phpcs-security.xml.dist) =="
	run_php vendor/bin/phpcs --standard=phpcs-security.xml.dist "${php_files[@]}" || status=1
else
	echo "lint-staged: no staged PHP files."
fi

js_files=()
scss_files=()
for f in "${asset_files[@]}"; do
	case "$f" in
		*.scss|*.css) scss_files+=("$f") ;;
		*) js_files+=("$f") ;;
	esac
done

if [ "${#js_files[@]}" -gt 0 ]; then
	echo "== Wynko staged JS: ESLint (@wordpress/eslint-plugin) =="
	node_modules/.bin/wp-scripts lint-js "${js_files[@]}" || status=1
fi

if [ "${#scss_files[@]}" -gt 0 ]; then
	echo "== Wynko staged CSS/SCSS: stylelint (@wordpress/stylelint-config) =="
	node_modules/.bin/wp-scripts lint-style "${scss_files[@]}" || status=1
fi

[ "$status" -eq 0 ] && echo "lint-staged: OK"
exit "$status"
