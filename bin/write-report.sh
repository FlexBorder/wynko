#!/usr/bin/env bash
#
# Wynko pre-commit report — runs the fast pre-commit tier (lint-staged.sh,
# unit-tests.sh, sbom-check.sh), captures every check's output regardless of
# pass/fail, prints a failing check's output to stderr (so a blocked commit
# still explains itself at the terminal, not only in the report), and writes
# one timestamped Markdown report to ../wynko-reports/ (a sibling of the repo
# checkout, deliberately outside git — see CONTRIBUTING.md). Exits non-zero
# if any check failed, so .githooks/pre-commit still blocks the commit.
#
# This tier is deliberately fast and offline: staged-file lint + the
# WordPress-free unit suite + the SBOM no-op. The heavier checks —
# full-tree PHPCS, PHPStan, Semgrep, Plugin Check, and the PHP 8.0-8.5
# matrix — run once per branch in bin/gate.sh and bin/php-matrix.sh, the
# first steps of bin/merge-to-main.sh, not on every commit. See
# TECHNICAL_DEBT.md TD-073.
#
# Usage: bin/write-report.sh
#
set -uo pipefail

root="$(git rev-parse --show-toplevel)"
cd "$root"

report_dir="$(cd "$root/.." && pwd)/wynko-reports"
mkdir -p "$report_dir"

timestamp="$(date -u +%Y-%m-%dT%H-%M-%SZ)"
commit="$(git rev-parse --short HEAD 2>/dev/null || echo 'uncommitted')"
branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
report_file="$report_dir/$timestamp-$commit.md"

{
	echo "# Wynko pre-commit report"
	echo
	echo "- **When:** $timestamp"
	echo "- **Branch:** $branch"
	echo "- **Commit (HEAD before this commit):** $commit"
	echo
} >"$report_file"

overall=0

run_check() {
	local name="$1" cmd="$2"
	local output exit_code

	output="$("$root/$cmd" 2>&1)"
	exit_code=$?
	if [ "$exit_code" -ne 0 ]; then
		overall=1
		echo "$output" >&2
	fi

	{
		echo "## $name — $([ "$exit_code" -eq 0 ] && echo PASS || echo FAIL) (exit $exit_code)"
		echo
		echo '```'
		echo "$output"
		echo '```'
		echo
	} >>"$report_file"
}

run_check "bin/lint-staged.sh" "bin/lint-staged.sh"
run_check "bin/unit-tests.sh" "bin/unit-tests.sh"
run_check "bin/sbom-check.sh" "bin/sbom-check.sh"

echo "write-report: report written to $report_file"

exit "$overall"
