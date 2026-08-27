#!/usr/bin/env bash
#
# Wynko CSS/SCSS coding-standards check — runs stylelint against
# @wordpress/stylelint-config (via `wp-scripts lint-style`) over every
# stylesheet in src/. Node tool, no Docker needed. Run `npm run
# lint:style:fix` to auto-correct what's fixable.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -x node_modules/.bin/wp-scripts ]; then
	echo "style-lint: node_modules/.bin/wp-scripts not found — run 'npm install' first." >&2
	exit 1
fi

echo "== Wynko CSS/SCSS coding standards (stylelint, @wordpress/stylelint-config) =="
npm run lint:style
echo "style-lint: OK"
