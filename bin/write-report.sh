#!/usr/bin/env bash
#
# Wynko pre-commit report — runs the ten pre-commit gate scripts
# (security-scan.sh, coding-standards.sh, style-lint.sh, js-lint.sh,
# unit-tests.sh, static-analysis.sh, semgrep-scan.sh, sbom-check.sh,
# wp-org-check.sh, plugin-check.sh), captures every check's output
# regardless of pass/fail, prints a failing check's output to stderr (so a
# blocked commit still explains itself at the terminal, not only in the
# report), and writes one timestamped Markdown report to
# ../wynko-reports/ (a sibling of the repo checkout, deliberately outside
# git — see CONTRIBUTING.md). Exits non-zero if any check failed, so
# .githooks/pre-commit still blocks the commit.
#
# plugin-check.sh requires `npx @wordpress/env start` already running — if
# it isn't, that check fails closed rather than being silently skipped.
# semgrep-scan.sh needs network access to fetch its rulesets.
#
# All PHP checks here run a single version (whatever's local, or the
# php:8.5-cli Docker fallback). bin/merge-to-main.sh runs the full
# PHP 8.0-8.5 matrix instead, at merge time — see bin/php-matrix.sh.
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

run_check "bin/security-scan.sh" "bin/security-scan.sh"
run_check "bin/coding-standards.sh" "bin/coding-standards.sh"
run_check "bin/style-lint.sh" "bin/style-lint.sh"
run_check "bin/js-lint.sh" "bin/js-lint.sh"
run_check "bin/unit-tests.sh" "bin/unit-tests.sh"
run_check "bin/static-analysis.sh" "bin/static-analysis.sh"
run_check "bin/semgrep-scan.sh" "bin/semgrep-scan.sh"
run_check "bin/sbom-check.sh" "bin/sbom-check.sh"
run_check "bin/wp-org-check.sh" "bin/wp-org-check.sh"
run_check "bin/plugin-check.sh" "bin/plugin-check.sh"

echo "write-report: report written to $report_file"

exit "$overall"
