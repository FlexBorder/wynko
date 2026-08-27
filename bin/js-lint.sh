#!/usr/bin/env bash
#
# Wynko JavaScript coding-standards check — runs ESLint against
# @wordpress/eslint-plugin (via `wp-scripts lint-js`) over src/. Node tool,
# no Docker needed. Run `npm run lint:js:fix` to auto-correct what's fixable.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ ! -x node_modules/.bin/wp-scripts ]; then
	echo "js-lint: node_modules/.bin/wp-scripts not found — run 'npm install' first." >&2
	exit 1
fi

echo "== Wynko JS coding standards (ESLint, @wordpress/eslint-plugin) =="
npm run lint:js
echo "js-lint: OK"
