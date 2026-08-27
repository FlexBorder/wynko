#!/usr/bin/env bash
#
# Wynko SBOM freshness gate — fails when the dependency lock files moved but the
# SBOM did not. Used by the .githooks/pre-commit hook and reused in CI.
#
# Two modes:
#   (default)      Cheap: does nothing unless a lock file is staged, in which
#                  case it falls through to the full check below. This is what
#                  the pre-commit hook runs, so the overwhelming majority of
#                  commits pay nothing.
#   --regenerate   Rebuild the SBOM and fail on any diff. Slower (Docker +
#                  network); CI and the release flow run this unconditionally.
#
# The default mode cannot simply demand that sbom/ be staged alongside a lock
# file. The SBOM covers runtime dependencies only, so bumping a dev tool changes
# the lock and changes nothing here — that would be an unsatisfiable demand.
# Only regenerating can answer the question, so on lock changes it does.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

mode="${1:-staged}"

if [ "$mode" = "staged" ]; then
	echo "== Wynko SBOM check (staged) =="

	if ! git diff --cached --name-only | grep -qE '(^|/)(composer\.lock|package-lock\.json)$'; then
		echo "sbom-check: OK — no dependency lock file staged."
		exit 0
	fi

	echo "sbom-check: a lock file is staged; verifying the SBOM against it."
fi

bin/sbom-generate.sh

# Worktree against the index, not against HEAD. Regeneration has just run, so
# any difference here means what is staged (or committed, when nothing is
# staged) does not match the lock files. Staging the regenerated file is what
# clears it — which `git status --porcelain` could never see, because it reports
# a staged-and-clean file as modified and so rejected the very state it asked
# for.
drift="$(git diff --name-only -- 'sbom/*.cdx.json')"

if [ -n "$drift" ]; then
	echo >&2
	echo "sbom-check: the SBOM is out of date. Regenerated output differs:" >&2
	echo "$drift" >&2
	echo >&2
	echo "A runtime dependency changed. Stage the regenerated sbom/ files." >&2
	exit 1
fi

echo "sbom-check: OK — SBOM matches the lock files."
