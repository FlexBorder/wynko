#!/usr/bin/env bash
#
# Wynko Semgrep scan — runs the same rule packs CI's security job uses
# (p/owasp-top-ten, p/php), same --error hard-fail behavior, via the
# official semgrep/semgrep Docker image. Needs network access to fetch the
# rulesets from Semgrep's registry, same requirement CI already has.
#
# Run as root inside the container (unlike the PHP scripts' -u mapping):
# semgrep only reads the repo, it never writes into it, so there's no
# root-owned-file risk, and pinning to the host UID would leave semgrep
# without a writable $HOME for its registry cache.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if ! command -v docker >/dev/null 2>&1; then
	echo "semgrep-scan: requires Docker." >&2
	exit 1
fi

echo "== Wynko Semgrep scan (p/owasp-top-ten, p/php) =="
docker run --rm -v "$PWD":/src -w /src semgrep/semgrep \
	semgrep scan --config p/owasp-top-ten --config p/php --error --quiet
echo "semgrep-scan: OK"
